<?php

namespace App\Services;

/** Bounded Word 97–2003 text reader. Does not execute macros or reproduce layout. */
class LegacyWordText
{
    public static function read(string $bytes): string
    {
        if (strlen($bytes) < 512 || substr($bytes, 0, 8) !== hex2bin('d0cf11e0a1b11ae1')) {
            throw new \RuntimeException('Not a compound Word document.');
        }
        $shift = self::number($bytes, 30, 2);
        if (! in_array($shift, [9, 12], true) || self::number($bytes, 32, 2) !== 6) {
            throw new \RuntimeException('Unsupported compound document geometry.');
        }
        $sectorSize = 1 << $shift;
        $sectorCount = (int) (strlen($bytes) / $sectorSize) - 1;
        $sector = static function (int $id) use ($bytes, $sectorSize, $sectorCount): string {
            if ($id < 0 || $id >= $sectorCount) {
                throw new \RuntimeException('Invalid document sector.');
            }

            return substr($bytes, ($id + 1) * $sectorSize, $sectorSize);
        };
        $fatCount = self::number($bytes, 44);
        if ($fatCount > $sectorCount) {
            throw new \RuntimeException('Invalid allocation table size.');
        }
        $fatSectors = [];
        for ($index = 0; $index < 109; $index++) {
            $id = self::number($bytes, 76 + 4 * $index);
            if ($id < 0xfffffffa) {
                $fatSectors[] = $id;
            }
        }
        $difat = self::number($bytes, 68);
        $difatCount = self::number($bytes, 72);
        $seen = [];
        if ($difatCount > $sectorCount) {
            throw new \RuntimeException('Invalid extended allocation table.');
        }
        for ($index = 0; $index < $difatCount; $index++) {
            if (isset($seen[$difat])) {
                throw new \RuntimeException('Cyclic allocation table.');
            }
            $seen[$difat] = true;
            $block = $sector($difat);
            for ($entry = 0; $entry < $sectorSize / 4 - 1; $entry++) {
                $id = self::number($block, $entry * 4);
                if ($id < 0xfffffffa) {
                    $fatSectors[] = $id;
                }
            }
            $difat = self::number($block, $sectorSize - 4);
        }
        if (count($fatSectors) < $fatCount) {
            throw new \RuntimeException('Incomplete allocation table.');
        }
        $fat = [];
        foreach (array_slice($fatSectors, 0, $fatCount) as $id) {
            array_push($fat, ...array_values(unpack('V*', $sector($id))));
        }
        $directory = self::chain(self::number($bytes, 48), $fat, $sector, $sectorCount);
        $entries = [];
        $root = null;
        for ($position = 0; $position + 128 <= strlen($directory); $position += 128) {
            $entry = substr($directory, $position, 128);
            $type = ord($entry[66]);
            if (! in_array($type, [2, 5], true)) {
                continue;
            }
            $nameLength = self::number($entry, 64, 2);
            if ($nameLength < 2 || $nameLength > 64 || $nameLength % 2 !== 0) {
                throw new \RuntimeException('Invalid stream name.');
            }
            $name = mb_convert_encoding(substr($entry, 0, $nameLength - 2), 'UTF-8', 'UTF-16LE');
            $size = self::number($entry, 120);
            if ($size > strlen($bytes) || self::number($entry, 124) !== 0) {
                throw new \RuntimeException('Stream exceeds the document size.');
            }
            $record = ['start' => self::number($entry, 116), 'size' => $size];
            if ($type === 5) {
                $root = $record;
            } else {
                $entries[$name] = $record;
            }
        }
        $miniFat = [];
        $miniStream = '';
        if ($root && $root['size'] > 0) {
            $miniStream = substr(self::chain($root['start'], $fat, $sector, $sectorCount), 0, $root['size']);
            $miniBytes = self::chain(self::number($bytes, 60), $fat, $sector, $sectorCount);
            if ($miniBytes !== '') {
                $miniFat = array_values(unpack('V*', $miniBytes));
            }
        }
        $read = static function (string $name) use ($entries, $fat, $sector, $sectorCount, $miniStream, $miniFat): string {
            if (! isset($entries[$name])) {
                throw new \RuntimeException('Word stream missing.');
            }
            $entry = $entries[$name];
            if ($entry['size'] < 4096) {
                $miniSector = static function (int $id) use ($miniStream): string {
                    if ($id < 0 || ($id + 1) * 64 > strlen($miniStream)) {
                        throw new \RuntimeException('Invalid mini sector.');
                    }

                    return substr($miniStream, $id * 64, 64);
                };
                $content = self::chain($entry['start'], $miniFat, $miniSector, (int) (strlen($miniStream) / 64));
            } else {
                $content = self::chain($entry['start'], $fat, $sector, $sectorCount);
            }
            if (strlen($content) < $entry['size']) {
                throw new \RuntimeException('Truncated Word stream.');
            }

            return substr($content, 0, $entry['size']);
        };
        $word = $read('WordDocument');
        $flags = self::number($word, 10, 2);
        if (($flags & 0x8100) !== 0 || self::number($word, 0, 2) !== 0xa5ec) {
            throw new \RuntimeException('Protected or unsupported Word document.');
        }
        $table = $read(($flags & 0x0200) !== 0 ? '1Table' : '0Table');
        $longCountPosition = 34 + self::number($word, 32, 2) * 2;
        $longStart = $longCountPosition + 2;
        $pairCountPosition = $longStart + self::number($word, $longCountPosition, 2) * 4;
        if (self::number($word, $pairCountPosition, 2) < 34) {
            throw new \RuntimeException('Word piece table missing.');
        }
        $clxPosition = $pairCountPosition + 2 + 33 * 8;
        $clxOffset = self::number($word, $clxPosition);
        $clxLength = self::number($word, $clxPosition + 4);
        if ($clxLength > strlen($table) || $clxOffset + $clxLength > strlen($table)) {
            throw new \RuntimeException('Invalid Word piece table range.');
        }
        $clx = substr($table, $clxOffset, $clxLength);
        $offset = 0;
        while ($offset < strlen($clx) && ord($clx[$offset]) === 1) {
            $offset += 3 + self::number($clx, $offset + 1, 2);
        }
        if ($offset >= strlen($clx) || ord($clx[$offset]) !== 2) {
            throw new \RuntimeException('Word text pieces unavailable.');
        }
        $length = self::number($clx, $offset + 1);
        if ($length < 4 || ($length - 4) % 12 !== 0 || $offset + 5 + $length > strlen($clx)) {
            throw new \RuntimeException('Invalid text piece lengths.');
        }
        $pieces = substr($clx, $offset + 5, $length);
        $count = (int) (($length - 4) / 12);
        $mainCharacters = min(200000, self::number($word, $longStart + 12));
        $text = '';
        for ($index = 0; $index < $count; $index++) {
            $start = self::number($pieces, $index * 4);
            $end = self::number($pieces, ($index + 1) * 4);
            if ($end < $start) {
                throw new \RuntimeException('Unsorted text pieces.');
            }
            if ($start >= $mainCharacters) {
                break;
            }
            $characters = min($end, $mainCharacters) - $start;
            $location = self::number($pieces, ($count + 1) * 4 + $index * 8 + 2);
            $compressed = ($location & 0x40000000) !== 0;
            $location &= 0x3fffffff;
            $location = $compressed ? (int) ($location / 2) : $location;
            $byteCount = $characters * ($compressed ? 1 : 2);
            if ($location + $byteCount > strlen($word)) {
                throw new \RuntimeException('Text extends beyond the Word stream.');
            }
            $text .= mb_convert_encoding(substr($word, $location, $byteCount), 'UTF-8', $compressed ? 'Windows-1252' : 'UTF-16LE');
        }

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', str_replace("\r", "\n", $text)) ?? '';
    }

    /** @param list<int> $fat */
    private static function chain(int $start, array $fat, callable $sector, int $limit): string
    {
        $bytes = '';
        $seen = [];
        while ($start < 0xfffffffa) {
            if (isset($seen[$start]) || count($seen) >= $limit || ! isset($fat[$start])) {
                throw new \RuntimeException('Broken or cyclic document allocation.');
            }
            $seen[$start] = true;
            $bytes .= $sector($start);
            $start = $fat[$start];
        }

        return $bytes;
    }

    private static function number(string $bytes, int $offset, int $length = 4): int
    {
        if ($offset < 0 || $offset + $length > strlen($bytes)) {
            throw new \RuntimeException('Truncated binary document.');
        }

        return unpack($length === 2 ? 'vvalue' : 'Vvalue', substr($bytes, $offset, $length))['value'];
    }
}

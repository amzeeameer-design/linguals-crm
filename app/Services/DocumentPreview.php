<?php

namespace App\Services;

class DocumentPreview
{
    /** @param array<string, mixed> $document */
    public static function render(array $document, string $bytes): string
    {
        if ($document['mime'] === 'application/pdf') {
            return $bytes;
        }
        if (str_starts_with($document['mime'], 'image/')) {
            return self::image($bytes);
        }
        $extracted = DocumentText::read($bytes, $document['mime'], $document['original_name']);
        $text = $extracted['text'] ?: 'Readable content could not be extracted. Ask for a PDF copy and use Download original to inspect this file. This preview cannot be used to assess the original.';

        return self::text($document['original_name'], $extracted['coverage']."\n\n".$text);
    }

    private static function image(string $bytes): string
    {
        $source = imagecreatefromstring($bytes);
        if (! $source) {
            return self::text('Document preview', 'The uploaded image could not be decoded. Review the original file.');
        }
        $ratio = min(1, 2200 / max(imagesx($source), imagesy($source)));
        $width = max(1, (int) (imagesx($source) * $ratio));
        $height = max(1, (int) (imagesy($source) * $ratio));
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        ob_start();
        imagejpeg($image, null, 92);
        $jpeg = ob_get_clean();
        imagedestroy($source);
        imagedestroy($image);
        $scale = min(515 / $width, 762 / $height);
        $drawWidth = $width * $scale;
        $drawHeight = $height * $scale;
        $stream = sprintf("q %.3f 0 0 %.3f %.3f %.3f cm /Image Do Q\n", $drawWidth, $drawHeight, (595 - $drawWidth) / 2, (842 - $drawHeight) / 2);

        return self::pack([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [5 0 R] /Count 1 >>',
            '<< /Type /XObject /Subtype /Image /Width '.$width.' /Height '.$height.' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg)." >>\nstream\n".$jpeg."\nendstream",
            self::stream($stream),
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Image 3 0 R >> >> /Contents 4 0 R >>',
        ]);
    }

    private static function text(string $title, string $text): string
    {
        $font = file_get_contents(public_path('vendor/pdfjs/standard_fonts/LiberationSans-Regular.ttf'));
        $cmap = self::glyphMap($font);
        $lines = [];
        foreach (explode("\n", $title."\nTEXT PREVIEW — original formatting is not reproduced\n\n".mb_substr($text, 0, 120000)) as $line) {
            $line = str_replace(["\r", "\t"], ['', '    '], $line);
            if ($line === '') {
                $lines[] = '';
            }
            while (mb_strlen($line) > 82) {
                $part = mb_substr($line, 0, 82);
                $space = mb_strrpos($part, ' ');
                $length = $space !== false && $space > 40 ? $space : 82;
                $lines[] = mb_substr($line, 0, $length);
                $line = ltrim(mb_substr($line, $length));
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $characters = array_combine(range(32, 126), range(32, 126));
        foreach (mb_str_split(implode('', $lines)) as $character) {
            $code = mb_ord($character);
            if ($code <= 65535) {
                $characters[$code] = $code;
            }
        }
        $mapping = str_repeat("\0", 131072);
        foreach ($characters as $code) {
            $glyph = self::glyph($cmap, $code);
            $mapping[$code * 2] = chr($glyph >> 8);
            $mapping[$code * 2 + 1] = chr($glyph & 255);
        }
        $unicode = "/CIDInit /ProcSet findresource begin\n12 dict begin begincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /LingualsUnicode def /CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
        foreach (array_chunk($characters, 100) as $chunk) {
            $unicode .= count($chunk)." beginbfchar\n";
            foreach ($chunk as $code) {
                $unicode .= sprintf('<%04X> <%04X>', $code, $code)."\n";
            }
            $unicode .= "endbfchar\n";
        }
        $unicode .= "endcmap CMapName currentdict /CMap defineresource pop end end";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>', '',
            '<< /Type /Font /Subtype /Type0 /BaseFont /LiberationSans /Encoding /Identity-H /DescendantFonts [4 0 R] /ToUnicode 8 0 R >>',
            '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /LiberationSans /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor 5 0 R /DW 600 /CIDToGIDMap 7 0 R >>',
            '<< /Type /FontDescriptor /FontName /LiberationSans /Flags 32 /FontBBox [-600 -400 2200 1100] /ItalicAngle 0 /Ascent 905 /Descent -212 /CapHeight 690 /StemV 80 /FontFile2 6 0 R >>',
            self::stream($font, '/Length1 '.strlen($font)), self::stream($mapping), self::stream($unicode),
        ];
        $references = [];
        $pages = array_chunk($lines, 48);
        foreach ($pages as $index => $page) {
            $stream = "0.09 0.42 0.35 rg\n";
            foreach ($page as $lineNumber => $line) {
                $encoded = strtoupper(bin2hex(mb_convert_encoding($line, 'UTF-16BE', 'UTF-8')));
                $stream .= 'BT /F1 10 Tf 1 0 0 1 42 '.(796 - $lineNumber * 15).' Tm <'.$encoded."> Tj ET\n";
            }
            $footer = bin2hex(mb_convert_encoding('The Linguals | Preview | Page '.($index + 1).' / '.count($pages), 'UTF-16BE', 'UTF-8'));
            $stream .= 'BT /F1 8 Tf 1 0 0 1 42 36 Tm <'.$footer."> Tj ET\n";
            $objects[] = self::stream($stream);
            $content = count($objects);
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$content.' 0 R >>';
            $references[] = count($objects).' 0 R';
        }
        $objects[1] = '<< /Type /Pages /Kids ['.implode(' ', $references).'] /Count '.count($pages).' >>';

        return self::pack($objects);
    }

    /** @return array<string, mixed> */
    private static function glyphMap(string $font): array
    {
        $tables = unpack('ncount', substr($font, 4, 2))['count'];
        for ($index = 0; $index < $tables; $index++) {
            $entry = substr($font, 12 + $index * 16, 16);
            if (substr($entry, 0, 4) !== 'cmap') {
                continue;
            }
            $offset = unpack('Noffset', substr($entry, 8, 4))['offset'];
            $count = unpack('ncount', substr($font, $offset + 2, 2))['count'];
            for ($table = 0; $table < $count; $table++) {
                $record = unpack('nplatform/nencoding/Noffset', substr($font, $offset + 4 + $table * 8, 8));
                $start = $offset + $record['offset'];
                if ($record['platform'] === 3 && $record['encoding'] === 1 && unpack('nformat', substr($font, $start, 2))['format'] === 4) {
                    $length = unpack('nlength', substr($font, $start + 2, 2))['length'];

                    return ['bytes' => substr($font, $start, $length), 'segments' => unpack('ncount', substr($font, $start + 6, 2))['count'] / 2];
                }
            }
        }

        throw new \RuntimeException('The preview font is missing its Unicode character map.');
    }

    /** @param array<string, mixed> $map */
    private static function glyph(array $map, int $code): int
    {
        $bytes = $map['bytes'];
        $segments = (int) $map['segments'];
        $word = static fn (int $offset): int => unpack('nvalue', substr($bytes, $offset, 2))['value'];
        for ($index = 0; $index < $segments; $index++) {
            $end = $word(14 + 2 * $index);
            $start = $word(16 + 2 * $segments + 2 * $index);
            if ($code < $start || $code > $end) {
                continue;
            }
            $delta = $word(16 + 4 * $segments + 2 * $index);
            $rangePosition = 16 + 6 * $segments + 2 * $index;
            $range = $word($rangePosition);
            if ($range === 0) {
                return ($code + $delta) & 65535;
            }
            $position = $rangePosition + $range + 2 * ($code - $start);
            if ($position + 2 > strlen($bytes)) {
                return 0;
            }
            $glyph = $word($position);

            return $glyph === 0 ? 0 : ($glyph + $delta) & 65535;
        }

        return 0;
    }

    private static function stream(string $bytes, string $properties = ''): string
    {
        return '<< /Length '.strlen($bytes).' '.$properties." >>\nstream\n".$bytes."\nendstream";
    }

    /** @param list<string> $objects */
    private static function pack(array $objects): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".count($offsets)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf."trailer\n<< /Size ".count($offsets).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF";
    }
}

<?php

namespace Tests\Unit;

use App\Services\LegacyWordText;
use PHPUnit\Framework\TestCase;

class LegacyWordTextTest extends TestCase
{
    private function document(string $text): string
    {
        $header = str_repeat("\0", 512);
        $set = static function (string &$bytes, int $offset, string $value): void {
            $bytes = substr_replace($bytes, $value, $offset, strlen($value));
        };
        $set($header, 0, hex2bin('d0cf11e0a1b11ae1'));
        $set($header, 26, pack('v', 3));
        $set($header, 28, pack('v', 0xfffe));
        $set($header, 30, pack('v', 9));
        $set($header, 32, pack('v', 6));
        $set($header, 44, pack('V', 1));
        $set($header, 48, pack('V', 0));
        $set($header, 56, pack('V', 4096));
        $set($header, 60, pack('V', 0xfffffffe));
        $set($header, 68, pack('V', 0xfffffffe));
        for ($index = 0; $index < 109; $index++) {
            $set($header, 76 + 4 * $index, pack('V', $index === 0 ? 17 : 0xffffffff));
        }
        $entry = static function (string $name, int $type, int $start, int $size) use ($set): string {
            $value = str_repeat("\0", 128);
            $nameBytes = mb_convert_encoding($name."\0", 'UTF-16LE', 'UTF-8');
            $set($value, 0, $nameBytes);
            $set($value, 64, pack('v', strlen($nameBytes)));
            $value[66] = chr($type);
            $value[67] = chr(1);
            $set($value, 68, pack('V3', 0xffffffff, 0xffffffff, 0xffffffff));
            $set($value, 116, pack('V', $start));
            $set($value, 120, pack('V', $size));

            return $value;
        };
        $directory = str_pad($entry('Root Entry', 5, 0xfffffffe, 0).$entry('WordDocument', 2, 1, 4096).$entry('1Table', 2, 9, 4096), 512, "\0");
        $characters = strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2;
        $word = str_repeat("\0", 4096);
        $set($word, 0, pack('v', 0xa5ec));
        $set($word, 2, pack('v', 0x00c1));
        $set($word, 10, pack('v', 0x0200));
        $set($word, 32, pack('v', 14));
        $set($word, 62, pack('v', 22));
        $set($word, 76, pack('V', $characters));
        $set($word, 152, pack('v', 93));
        $set($word, 418, pack('V2', 0, 21));
        $set($word, 512, mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'));
        $piece = pack('V2', 0, $characters).pack('vVv', 0, 512, 0);
        $table = str_pad(chr(2).pack('V', strlen($piece)).$piece, 4096, "\0");
        $fat = '';
        for ($index = 0; $index < 128; $index++) {
            $next = match (true) {
                $index === 0, $index === 8, $index === 16 => 0xfffffffe,
                $index >= 1 && $index <= 15 => $index + 1,
                $index === 17 => 0xfffffffd,
                default => 0xffffffff,
            };
            $fat .= pack('V', $next);
        }

        return $header.$directory.$word.$table.$fat;
    }

    public function test_legacy_word_text_preserves_unicode_and_paragraphs(): void
    {
        $text = "José Applicant\rUrdu اردو\rFrench translation experience";
        $this->assertSame(str_replace("\r", "\n", $text), LegacyWordText::read($this->document($text)));
    }

    public function test_encrypted_and_cyclic_legacy_documents_are_rejected(): void
    {
        $bytes = $this->document('Private applicant record');
        $bytes = substr_replace($bytes, pack('v', 0x0300), 512 * 2 + 10, 2);
        $this->expectException(\RuntimeException::class);
        LegacyWordText::read($bytes);
    }

    public function test_cyclic_allocation_never_loops_indefinitely(): void
    {
        $bytes = $this->document('Sample applicant');
        $bytes = substr_replace($bytes, pack('V', 1), 512 * 18 + 4, 4);
        $this->expectException(\RuntimeException::class);
        LegacyWordText::read($bytes);
    }

    public function test_small_table_stream_uses_mini_allocation(): void
    {
        $text = 'Small CV with a mini table stream';
        $bytes = $this->document($text);
        $bytes = substr_replace($bytes, pack('V2', 18, 1), 60, 8);
        $bytes = substr_replace($bytes, pack('V', 9), 512 + 116, 4);
        $bytes = substr_replace($bytes, pack('V', 4096), 512 + 120, 4);
        $bytes = substr_replace($bytes, pack('V', 0), 512 + 256 + 116, 4);
        $bytes = substr_replace($bytes, pack('V', 21), 512 + 256 + 120, 4);
        $bytes = substr_replace($bytes, pack('V', 0xfffffffe), 512 * 18 + 18 * 4, 4);
        $bytes .= pack('V', 0xfffffffe).str_repeat(pack('V', 0xffffffff), 127);
        $this->assertSame($text, LegacyWordText::read($bytes));
    }
}

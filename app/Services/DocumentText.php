<?php

namespace App\Services;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

class DocumentText
{
    public static function read(string $bytes, string $mime, string $name): array
    {
        if (str_ends_with(strtolower($name), '.docx')) {
            $tmp = tempnam(sys_get_temp_dir(), 'linguals-word');
            file_put_contents($tmp, $bytes);
            $zip = new \ZipArchive;
            $opened = false;
            try {
                if ($zip->open($tmp) !== true) {
                    return ['text' => '', 'coverage' => 'Unreadable Word file'];
                }$opened = true;
                $stat = $zip->statName('word/document.xml');
                if (! $stat || $stat['size'] > 12000000) {
                    return ['text' => '', 'coverage' => 'Word content is missing or too large to preview'];
                }$xml = $zip->getFromName('word/document.xml');
                if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                    return ['text' => '', 'coverage' => 'Word XML contains a document type or entity declaration. Ask for a clean PDF copy.'];
                }
                $dom = new \DOMDocument;
                $old = libxml_use_internal_errors(true);
                try {
                    if (! $dom->loadXML($xml, LIBXML_NONET)) {
                        return ['text' => '', 'coverage' => 'Unreadable Word XML'];
                    }$xpath = new \DOMXPath($dom);
                    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                    $lines = [];
                    foreach ($xpath->query('//w:p') as $p) {
                        $line = '';
                        foreach ($xpath->query('.//w:t', $p) as $t) {
                            $line .= $t->textContent;
                        }$lines[] = $line;
                    }

return ['text' => mb_substr(implode("\n", $lines), 0, 200000), 'coverage' => 'Word text preview; original formatting and embedded images are not reproduced'];
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($old);
                }
            } finally {
                if ($opened) {
                    $zip->close();
                }@unlink($tmp);
            }
        }
        if ($mime === 'application/pdf') {
            if (strlen($bytes) > 12 * 1024 * 1024) {
                return ['text' => '', 'coverage' => 'Large PDF: text extraction skipped to protect shared-hosting resources. Preview and review the original manually.'];
            }
            try {
                $config = new Config;
                $config->setRetainImageContent(false);
                $config->setDecodeMemoryLimit(8 * 1024 * 1024);
                $parser = new Parser([], $config);
                $pdf = $parser->parseContent($bytes);

                return ['text' => mb_substr($pdf->getText(), 0, 200000), 'coverage' => 'Readable PDF text only; scanned images are not checked'];
            } catch (\Throwable $e) {
                return ['text' => '', 'coverage' => 'This PDF needs manual review: no readable text or protected content'];
            }
        }
        if (str_ends_with(strtolower($name), '.doc')) {
            try {
                return ['text' => LegacyWordText::read($bytes), 'coverage' => 'Legacy Word text preview. Formatting, embedded images, tracked changes and macros are not reproduced or executed; review the original for context.'];
            } catch (\Throwable $error) {
                return ['text' => '', 'coverage' => 'Protected, corrupt or unsupported legacy DOC. Ask for a readable PDF/DOCX copy.'];
            }
        }

        return ['text' => '', 'coverage' => 'Image document needs manual visual review; no OCR or authenticity verification is performed'];
    }
}

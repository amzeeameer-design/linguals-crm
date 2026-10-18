<?php

namespace App\Services;

class AgreementPdf
{
    /** @param array<string, string> $document @param array<string, mixed> $signature */
    public static function render(array $document, array $signature = []): string
    {
        $pages = [];
        $lines = [];
        $y = 718;
        $add = function (string $text, bool $bold = false, int $size = 10) use (&$pages, &$lines, &$y): void {
            $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
            $width = $size === 16 ? 55 : 92;
            $wrapped = explode("\n", wordwrap($encoded, $width, "\n", true));
            $height = count($wrapped) * ($size + 5) + 8;
            if ($lines && ($height < 620 && $y - $height < 90 || $bold && $y < 150)) {
                $pages[] = $lines;
                $lines = [];
                $y = 718;
            }
            foreach ($wrapped as $line) {
                if ($y < 90) {
                    $pages[] = $lines;
                    $lines = [];
                    $y = 718;
                }
                $lines[] = [$line, $y, $bold ? 'F2' : 'F1', $size];
                $y -= $size + 5;
            }
            $y -= 8;
        };
        $add($document['title'], true, 16);
        $add('Version '.$document['version'].' | Contractor: '.($document['contractor_name'] ?? 'Preview applicant'));
        foreach (preg_split('/\n\s*\n/', $document['body']) as $paragraph) {
            $add(trim($paragraph), strlen(trim($paragraph)) < 75 && strtoupper(trim($paragraph)) === trim($paragraph));
        }
        $add('ACCEPTANCE & ELECTRONIC SIGNATURE', true, 12);
        $add('I have read, understood, and accept the terms and conditions set out in these Agreements, and I hereby provide the guarantee referenced herein.');
        $add('The Linguals representative: '.($document['company_signatory'] ?? '').' | '.($document['company_title'] ?? ''));
        $add('Contractor signature: '.($signature['name'] ?? 'Awaiting applicant signature'), true, 12);
        $add('Title: '.($signature['title'] ?? 'Not supplied').' | Signed: '.($signature['signed_at'] ?? 'Not yet signed'));
        if ($signature) {
            $add('Account: '.$signature['email'].' | Agreement reference: '.$signature['agreement_id']);
            $add('Document SHA-256: '.$signature['document_hash']);
        }
        if ($lines) {
            $pages[] = $lines;
        }
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $images = [];
        foreach (['Logo' => ['linguals-logo.png', 100], 'Watermark' => ['linguals-favicon.png', 8]] as $name => [$file, $opacity]) {
            $source = imagecreatefrompng(public_path('branding/'.$file));
            $flattened = imagecreatetruecolor(imagesx($source), imagesy($source));
            imagefill($flattened, 0, 0, imagecolorallocate($flattened, 255, 255, 255));
            imagealphablending($flattened, true);
            imagecopy($flattened, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
            $image = imagecreatetruecolor(imagesx($source), imagesy($source));
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            imagecopymerge($image, $flattened, 0, 0, 0, 0, imagesx($source), imagesy($source), $opacity);
            ob_start();
            imagejpeg($image, null, 92);
            $bytes = ob_get_clean();
            $objects[] = '<< /Type /XObject /Subtype /Image /Width '.imagesx($image).' /Height '.imagesy($image).' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($bytes)." >>\nstream\n".$bytes."\nendstream";
            $images[$name] = count($objects);
            imagedestroy($image);
            imagedestroy($flattened);
            imagedestroy($source);
        }
        $references = [];
        $escape = fn (string $text): string => str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $text);
        foreach ($pages as $index => $page) {
            $stream = "q 220 0 0 57 48 766 cm /Logo Do Q\nq 230 0 0 255 183 292 cm /Watermark Do Q\n0.09 0.42 0.35 RG 48 749 m 547 749 l S\n";
            foreach ($page as [$line, $position, $font, $size]) {
                $stream .= 'BT /'.$font.' '.$size.' Tf 0.12 0.19 0.18 rg 48 '.$position.' Td ('.$escape($line).") Tj ET\n";
            }
            $footer = iconv('UTF-8', 'Windows-1252//TRANSLIT', $document['footer']);
            $stream .= "0.09 0.42 0.35 RG 48 64 m 547 64 l S\nBT /F1 8 Tf 0.35 0.45 0.42 rg 48 48 Td (".$escape($footer).") Tj ET\nBT /F1 8 Tf 48 34 Td (CONFIDENTIAL | Page ".($index + 1).' of '.count($pages).") Tj ET\n";
            $objects[] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."endstream";
            $content = count($objects);
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /Logo '.$images['Logo'].' 0 R /Watermark '.$images['Watermark'].' 0 R >> >> /Contents '.$content.' 0 R >>';
            $references[] = count($objects).' 0 R';
        }
        $objects[1] = '<< /Type /Pages /Kids ['.implode(' ', $references).'] /Count '.count($pages).' >>';
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
        return $pdf.'trailer' ."\n<< /Size ".count($offsets).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF";
    }
}

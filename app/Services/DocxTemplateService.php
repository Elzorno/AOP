<?php

namespace App\Services;

use ZipArchive;

class DocxTemplateService
{
    /**
     * Render a DOCX by replacing {{TOKENS}} inside the template XML parts.
     *
     * IMPORTANT: Placeholders must exist as contiguous text in the DOCX (single run).
     */
    public function render(string $templatePath, array $replacements, string $outputPath): void
    {
        if (!is_file($templatePath)) {
            throw new \RuntimeException('Template not found: ' . $templatePath);
        }

        $tmp = sys_get_temp_dir() . '/aop_docx_' . bin2hex(random_bytes(6));
        if (!@mkdir($tmp, 0700, true) && !is_dir($tmp)) {
            throw new \RuntimeException('Unable to create temp directory.');
        }

        $workDocx = $tmp . '/template.docx';
        copy($templatePath, $workDocx);

        $zip = new ZipArchive();
        if ($zip->open($workDocx) !== true) {
            throw new \RuntimeException('Unable to open DOCX template as ZIP.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'] ?? '';
            if (!str_starts_with($name, 'word/') || !str_ends_with($name, '.xml')) {
                continue;
            }

            $xml = $zip->getFromIndex($i);
            if ($xml === false) {
                continue;
            }

            $updated = $xml;
            foreach ($replacements as $token => $value) {
                $updated = $this->replaceTokenInXml($updated, (string) $token, (string) $value);
            }

            if ($updated !== $xml) {
                $zip->deleteName($name);
                $zip->addFromString($name, $updated);
            }
        }

        $zip->close();

        $outDir = dirname($outputPath);
        if (!is_dir($outDir) && !@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            throw new \RuntimeException('Unable to create output directory: ' . $outDir);
        }

        copy($workDocx, $outputPath);

        @unlink($workDocx);
        @rmdir($tmp);
    }

    private function replaceTokenInXml(string $xml, string $token, string $value): string
    {
        $placeholder = '{{' . $token . '}}';
        if (!str_contains($xml, $placeholder)) {
            return $xml;
        }

        return str_replace($placeholder, $this->wordXmlText($value), $xml);
    }

    private function wordXmlText(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        if ($value === '') {
            return '';
        }

        $lines = explode("\n", $value);
        $escapedLines = array_map(fn (string $line) => $this->wordXmlLine($line), $lines);

        return implode('</w:t><w:br/><w:t xml:space="preserve">', $escapedLines);
    }

    private function wordXmlLine(string $line): string
    {
        $parts = explode("\t", $line);
        $escaped = array_map(fn (string $part) => $this->xmlEscape($part), $parts);

        return implode('</w:t><w:tab/><w:t xml:space="preserve">', $escaped);
    }

    private function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

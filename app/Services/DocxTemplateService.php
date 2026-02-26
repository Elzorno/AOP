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

        // Escape replacements for XML.
        $safe = [];
        foreach ($replacements as $k => $v) {
            $safe[$k] = $this->xmlEscape((string)$v);
        }

        // Replace tokens in all word/*.xml parts.
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
            foreach ($safe as $token => $value) {
                $updated = str_replace('{{' . $token . '}}', $value, $updated);
            }

            if ($updated !== $xml) {
                $zip->deleteName($name);
                $zip->addFromString($name, $updated);
            }
        }

        $zip->close();

        // Ensure output dir
        $outDir = dirname($outputPath);
        if (!is_dir($outDir) && !@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            throw new \RuntimeException('Unable to create output directory: ' . $outDir);
        }

        copy($workDocx, $outputPath);

        // cleanup
        @unlink($workDocx);
        @rmdir($tmp);
    }

    private function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

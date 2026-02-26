<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class SyllabusRenderService
{
    /**
     * Render HTML to DOCX or PDF using LibreOffice (soffice) headless conversion.
     *
     * Host requirement:
     *  - Install LibreOffice in the LXC (package: libreoffice)
     *  - `soffice` must be available on PATH
     */
    public function renderHtmlTo(string $html, string $format, string $outDir, string $baseName): string
    {
        $format = strtolower(trim($format));
        if (!in_array($format, ['docx', 'pdf'], true)) {
            throw new \InvalidArgumentException('Unsupported format: ' . $format);
        }

        if (!is_dir($outDir) && !@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            throw new \RuntimeException('Unable to create output directory: ' . $outDir);
        }

        $soffice = $this->findSoffice();
        if ($soffice === null) {
            throw new \RuntimeException('LibreOffice is not installed or `soffice` is not available. Install `libreoffice` in the LXC.');
        }

        $tmpDir = rtrim(sys_get_temp_dir(), '/') . '/aop_syllabi_' . bin2hex(random_bytes(6));
        if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Unable to create temp directory.');
        }

        $htmlPath = $tmpDir . '/' . $baseName . '.html';
        file_put_contents($htmlPath, $html);

        $process = new Process([
            $soffice,
            '--headless',
            '--nologo',
            '--nolockcheck',
            '--nodefault',
            '--norestore',
            '--convert-to',
            $format,
            '--outdir',
            $outDir,
            $htmlPath,
        ]);
        $process->setTimeout(120);
        $process->run();

        @unlink($htmlPath);
        @rmdir($tmpDir);

        if (!$process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new \RuntimeException('LibreOffice conversion failed: ' . ($err !== '' ? $err : 'unknown error'));
        }

        $outPath = rtrim($outDir, '/') . '/' . $baseName . '.' . $format;
        if (!is_file($outPath)) {
            $candidates = glob(rtrim($outDir, '/') . '/*.' . $format) ?: [];
            if ($candidates) {
                usort($candidates, fn($a, $b) => filemtime($b) <=> filemtime($a));
                $outPath = $candidates[0];
            }
        }

        if (!is_file($outPath)) {
            throw new \RuntimeException('Converted file not found after LibreOffice conversion.');
        }

        return $outPath;
    }

    private function findSoffice(): ?string
    {
        foreach (['/usr/bin/soffice', '/usr/local/bin/soffice'] as $p) {
            if (is_file($p) && is_executable($p)) {
                return $p;
            }
        }

        $proc = new Process(['bash', '-lc', 'command -v soffice']);
        $proc->setTimeout(5);
        $proc->run();
        if ($proc->isSuccessful()) {
            $path = trim($proc->getOutput());
            if ($path !== '' && is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}

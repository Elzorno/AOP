#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

mkdir -p "$ROOT_DIR/app/Services"

cat > "$ROOT_DIR/app/Services/SyllabusRenderService.php" <<'PHP'
<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class SyllabusRenderService
{
    /**
     * Render syllabus HTML into DOCX or PDF.
     *
     * Preferred tools (best output):
     *  - DOCX: pandoc
     *  - PDF:  wkhtmltopdf
     *
     * Fallback:
     *  - LibreOffice headless (soffice) for both formats
     *
     * Host requirements (LXC):
     *  - apt-get install -y pandoc wkhtmltopdf libreoffice
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

        $tmpDir = rtrim(sys_get_temp_dir(), '/') . '/aop_syllabi_' . bin2hex(random_bytes(6));
        if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Unable to create temp directory.');
        }

        $htmlPath = $tmpDir . '/' . $baseName . '.html';
        file_put_contents($htmlPath, $html);

        try {
            if ($format === 'docx') {
                $out = $this->renderDocx($htmlPath, $outDir, $baseName);
            } else {
                $out = $this->renderPdf($htmlPath, $outDir, $baseName);
            }
        } finally {
            @unlink($htmlPath);
            @rmdir($tmpDir);
        }

        if (!is_file($out)) {
            throw new \RuntimeException('Converted file not found after rendering.');
        }

        return $out;
    }

    private function renderDocx(string $htmlPath, string $outDir, string $baseName): string
    {
        $outPath = rtrim($outDir, '/') . '/' . $baseName . '.docx';

        $pandoc = $this->findBinary(['pandoc']);
        if ($pandoc !== null) {
            $p = new Process([
                $pandoc,
                $htmlPath,
                '-o',
                $outPath,
            ]);
            $p->setTimeout(120);
            $p->run();

            if ($p->isSuccessful() && is_file($outPath)) {
                return $outPath;
            }

            $err = trim($p->getErrorOutput() ?: $p->getOutput());
            // Fall through to LibreOffice with context
            $this->debugLog('pandoc docx failed: ' . ($err !== '' ? $err : 'unknown error'));
        }

        // Fallback: LibreOffice
        return $this->renderWithLibreOffice($htmlPath, 'docx', $outDir, $baseName);
    }

    private function renderPdf(string $htmlPath, string $outDir, string $baseName): string
    {
        $outPath = rtrim($outDir, '/') . '/' . $baseName . '.pdf';

        $wk = $this->findBinary(['wkhtmltopdf']);
        if ($wk !== null) {
            // wkhtmltopdf needs local file access for embedded assets (even though we mostly inline CSS).
            $p = new Process([
                $wk,
                '--quiet',
                '--enable-local-file-access',
                '--print-media-type',
                $htmlPath,
                $outPath,
            ]);
            $p->setTimeout(120);
            $p->run();

            if ($p->isSuccessful() && is_file($outPath)) {
                return $outPath;
            }

            $err = trim($p->getErrorOutput() ?: $p->getOutput());
            $this->debugLog('wkhtmltopdf pdf failed: ' . ($err !== '' ? $err : 'unknown error'));
        }

        // Fallback: LibreOffice
        return $this->renderWithLibreOffice($htmlPath, 'pdf', $outDir, $baseName);
    }

    private function renderWithLibreOffice(string $htmlPath, string $format, string $outDir, string $baseName): string
    {
        $soffice = $this->findBinary(['/usr/bin/soffice', '/usr/local/bin/soffice', 'soffice']);
        if ($soffice === null) {
            throw new \RuntimeException('No renderer available. Install `pandoc` (docx), `wkhtmltopdf` (pdf), and/or `libreoffice` (fallback).');
        }

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
        $process->setTimeout(180);
        $process->run();

        if (!$process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new \RuntimeException('LibreOffice conversion failed: ' . ($err !== '' ? $err : 'unknown error'));
        }

        // LibreOffice sometimes names output based on input file
        $expected = rtrim($outDir, '/') . '/' . $baseName . '.' . $format;
        if (is_file($expected)) {
            return $expected;
        }

        $candidates = glob(rtrim($outDir, '/') . '/*.' . $format) ?: [];
        if ($candidates) {
            usort($candidates, fn($a, $b) => filemtime($b) <=> filemtime($a));
            return $candidates[0];
        }

        throw new \RuntimeException('Converted file not found after LibreOffice conversion.');
    }

    private function findBinary(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            // Absolute path
            if (is_string($c) && str_starts_with($c, '/')) {
                if (is_file($c) && is_executable($c)) {
                    return $c;
                }
                continue;
            }

            // Search PATH
            $proc = new Process(['bash', '-lc', 'command -v ' . escapeshellarg((string)$c) . ' 2>/dev/null || true']);
            $proc->setTimeout(5);
            $proc->run();
            $path = trim($proc->getOutput());
            if ($path !== '' && is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function debugLog(string $msg): void
    {
        // No-op by default; uncomment to log to php error log if desired.
        // error_log('[AOP SyllabusRenderService] ' . $msg);
    }
}
PHP

chown www-data:www-data "$ROOT_DIR/app/Services/SyllabusRenderService.php"
chmod 644 "$ROOT_DIR/app/Services/SyllabusRenderService.php"

echo "OK: Phase 12.1 applied (pandoc docx + wkhtmltopdf pdf, LO fallback)."

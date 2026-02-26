<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class DocxPdfConvertService
{
    public function docxToPdf(string $docxPath, string $outDir, string $baseName): string
    {
        if (!is_file($docxPath)) {
            throw new \RuntimeException('DOCX not found: ' . $docxPath);
        }

        if (!is_dir($outDir) && !@mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            throw new \RuntimeException('Unable to create output directory: ' . $outDir);
        }

        $soffice = $this->findBinary(['/usr/bin/soffice', '/usr/local/bin/soffice', 'soffice']);
        if ($soffice === null) {
            throw new \RuntimeException('LibreOffice (soffice) not found. Install: apt-get install -y libreoffice');
        }

        $p = new Process([
            $soffice,
            '--headless',
            '--nologo',
            '--nolockcheck',
            '--nodefault',
            '--norestore',
            '--convert-to',
            'pdf',
            '--outdir',
            $outDir,
            $docxPath,
        ]);
        $p->setTimeout(180);
        $p->run();

        if (!$p->isSuccessful()) {
            $err = trim($p->getErrorOutput() ?: $p->getOutput());
            throw new \RuntimeException('LibreOffice PDF conversion failed: ' . ($err !== '' ? $err : 'unknown error'));
        }

        $expected = rtrim($outDir, '/') . '/' . $baseName . '.pdf';
        if (is_file($expected)) {
            return $expected;
        }

        // LO may use original name
        $candidates = glob(rtrim($outDir, '/') . '/*.pdf') ?: [];
        if ($candidates) {
            usort($candidates, fn($a,$b) => filemtime($b) <=> filemtime($a));
            return $candidates[0];
        }

        throw new \RuntimeException('PDF not found after conversion.');
    }

    private function findBinary(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (is_string($c) && str_starts_with($c, '/')) {
                if (is_file($c) && is_executable($c)) {
                    return $c;
                }
                continue;
            }
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
}

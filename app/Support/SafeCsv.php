<?php

namespace App\Support;

final class SafeCsv
{
    /**
     * Write one spreadsheet-safe CSV row.
     *
     * A leading apostrophe tells common spreadsheet applications to treat a
     * formula-looking value as text. Ordinary registrar values are unchanged.
     *
     * @param  resource  $stream
     * @param  array<int, mixed>  $row
     */
    public static function write($stream, array $row): void
    {
        fputcsv($stream, array_map(self::escapeCell(...), $row), ',', '"', '');
    }

    public static function escapeCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if (preg_match('/^[\p{Z}\x00-\x20]*[=+\-@]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    /** @param resource $input @param resource $output */
    public static function sanitizeStream($input, $output): void
    {
        fwrite($output, "\xEF\xBB\xBF");
        $firstRow = true;

        while (($row = fgetcsv($input, null, ',', '"', '')) !== false) {
            if ($firstRow && isset($row[0]) && is_string($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]) ?? $row[0];
            }
            self::write($output, $row);
            $firstRow = false;
        }
    }

    public static function sanitizeString(string $csv): string
    {
        $input = fopen('php://temp', 'w+');
        $output = fopen('php://temp', 'w+');
        fwrite($input, $csv);
        rewind($input);
        self::sanitizeStream($input, $output);
        rewind($output);
        $result = stream_get_contents($output);
        fclose($input);
        fclose($output);

        return $result === false ? '' : $result;
    }
}

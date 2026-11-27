<?php

namespace App\Services\Vocus;

use App\Models\VocusPlan;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the Vocus "RC Bundles PlanID" sheet (.xlsx or .csv) and upserts vocus_plans by plan_id.
 *
 * No composer packages: an .xlsx file is a zip, read with ZipArchive + SimpleXML (shared strings, inline strings,
 * numeric cells, the first worksheet). Only the LEFT table is read (PlanID, ProductID, Technology, Mapping,
 * LineSpeed); the NBNCPEPlanID / router tables to its right are ignored.
 *
 * Upsert rules: an existing plan only has technology, mapping, speed tier and speeds updated (its name, prices,
 * scopes and active flag are never touched). A new plan is created inactive, with cost and sell 0 and the name
 * "{Technology} {LineSpeed} ({Mapping})", so an admin prices and activates it.
 */
final class VocusPlanSheetImporter
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_ROWS  = 2000;
    private const MAX_XML_BYTES = 20 * 1024 * 1024;
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * Parse an uploaded sheet into normalised rows.
     *
     * @return array{rows:list<array<string,mixed>>,warnings:list<string>}
     */
    public static function parseFile(string $path, string $extension): array
    {
        if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new RuntimeException('The file is missing or larger than ' . (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }

        $ext   = strtolower($extension);
        $table = match ($ext) {
            'xlsx'  => self::readXlsx($path),
            'csv', 'txt' => self::readCsv($path),
            default => throw new RuntimeException('Upload an .xlsx or .csv file.'),
        };

        return self::normalise($table);
    }

    // ----- readers -----------------------------------------------------------------------------

    /**
     * First worksheet of an .xlsx as a list of rows, each a list of cell strings (column index 0 = A).
     *
     * @return list<list<string>>
     */
    public static function readXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Reading .xlsx files needs the PHP zip extension (ZipArchive), which is not installed on this server. Install php-zip or upload the sheet as .csv.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('That file is not a valid .xlsx workbook.');
        }

        try {
            $sheetXml = self::zipText($zip, self::firstSheetPath($zip));
            if ($sheetXml === null) {
                throw new RuntimeException('The workbook has no readable worksheet.');
            }
            $shared = [];
            $ssXml  = self::zipText($zip, 'xl/sharedStrings.xml');
            if ($ssXml !== null) {
                $shared = self::sharedStrings($ssXml);
            }
        } finally {
            $zip->close();
        }

        return self::sheetRows($sheetXml, $shared);
    }

    /** @return list<list<string>> */
    public static function readCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('The file could not be read.');
        }
        $rows = [];
        $first = true;
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($first && isset($r[0])) {
                $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $r[0]);
                $first = false;
            }
            $rows[] = array_map(fn ($c) => trim((string) $c), $r);
            if (count($rows) > self::MAX_ROWS + 200) {
                break;
            }
        }
        fclose($fh);

        return $rows;
    }

    private static function zipText(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if (($stat['size'] ?? 0) > self::MAX_XML_BYTES) { // zip bomb guard
            throw new RuntimeException('The workbook is too large to read.');
        }
        $data = $zip->getFromName($name);

        return $data === false ? null : $data;
    }

    /** Path of the first worksheet, from workbook.xml + its rels (falls back to xl/worksheets/sheet1.xml). */
    private static function firstSheetPath(ZipArchive $zip): string
    {
        $wb   = self::zipText($zip, 'xl/workbook.xml');
        $rels = self::zipText($zip, 'xl/_rels/workbook.xml.rels');
        if ($wb !== null && $rels !== null) {
            $wbx = self::xml($wb);
            $rx  = self::xml($rels);
            $sheet = $wbx?->children(self::NS_MAIN)->sheets->sheet[0] ?? null;
            $rid   = $sheet ? (string) ($sheet->attributes(self::NS_REL)['id'] ?? '') : '';
            if ($rid !== '' && $rx) {
                foreach ($rx->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $rel) {
                    if (self::attr($rel, 'Id') === $rid) {
                        $target = self::attr($rel, 'Target');

                        return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . ltrim(preg_replace('#^\./#', '', $target), '/');
                    }
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** An unprefixed attribute. (Read through ->attributes(): on an element reached with children($ns), $el['x'] looks in that namespace and finds nothing.) */
    private static function attr(SimpleXMLElement $node, string $name): string
    {
        $a = $node->attributes();

        return isset($a[$name]) ? (string) $a[$name] : '';
    }

    private static function xml(string $data): ?SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($data, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $x === false ? null : $x;
    }

    /** @return list<string> */
    private static function sharedStrings(string $data): array
    {
        $x = self::xml($data);
        if (! $x) {
            return [];
        }
        $out = [];
        foreach ($x->children(self::NS_MAIN)->si as $si) {
            $out[] = self::richText($si);
        }

        return $out;
    }

    /** Text of an <si> / <is> element: a plain <t>, or the runs of rich text (phonetic runs <rPh> are skipped). */
    private static function richText(SimpleXMLElement $node): string
    {
        $c = $node->children(self::NS_MAIN);
        $text = '';
        if (isset($c->t)) {
            $text .= (string) $c->t;
        }
        foreach ($c->r as $run) {
            $text .= (string) $run->children(self::NS_MAIN)->t;
        }

        return $text;
    }

    /**
     * @param list<string> $shared
     * @return list<list<string>>
     */
    private static function sheetRows(string $data, array $shared): array
    {
        $x = self::xml($data);
        if (! $x) {
            throw new RuntimeException('The worksheet could not be read.');
        }

        $rows = [];
        $n = 0;
        foreach ($x->children(self::NS_MAIN)->sheetData->row ?? [] as $row) {
            if (++$n > self::MAX_ROWS + 200) {
                break;
            }
            $rIndex = self::attr($row, 'r') !== '' ? (int) self::attr($row, 'r') - 1 : count($rows);
            $cells  = [];
            $col    = 0;
            foreach ($row->children(self::NS_MAIN)->c as $c) {
                if (preg_match('/^([A-Z]+)\d+$/', self::attr($c, 'r'), $m)) {
                    $col = self::columnIndex($m[1]);
                }
                $cells[$col] = self::cellValue($c, $shared);
                $col++;
            }
            if ($cells === []) {
                continue;
            }
            $line = [];
            for ($i = 0, $max = max(array_keys($cells)); $i <= $max; $i++) {
                $line[] = $cells[$i] ?? '';
            }
            $rows[$rIndex] = $line;
        }
        ksort($rows);

        return array_values($rows);
    }

    private static function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /** @param list<string> $shared */
    private static function cellValue(SimpleXMLElement $c, array $shared): string
    {
        $type = self::attr($c, 't');
        $kids = $c->children(self::NS_MAIN);

        if ($type === 'inlineStr') {
            return trim(isset($kids->is) ? self::richText($kids->is) : '');
        }
        $v = isset($kids->v) ? trim((string) $kids->v) : '';

        return match ($type) {
            's'     => trim($shared[(int) $v] ?? ''),
            'b'     => $v === '1' ? 'TRUE' : 'FALSE',
            'e'     => '',
            'str'   => $v,
            default => self::numberText($v),
        };
    }

    /** 5939 / "5939.0" / "5.939E3" read back as the integer text "5939"; other numbers are kept as written. */
    private static function numberText(string $v): string
    {
        if ($v === '' || ! is_numeric($v)) {
            return $v;
        }
        $f = (float) $v;

        return floor($f) === $f && abs($f) < 1e15 ? (string) (int) $f : $v;
    }

    // ----- normalising -------------------------------------------------------------------------

    /**
     * Find the header row and read only the left table's columns.
     *
     * @param list<list<string>> $table
     * @return array{rows:list<array<string,mixed>>,warnings:list<string>}
     */
    public static function normalise(array $table): array
    {
        $header = null;
        $cols   = [];
        foreach ($table as $i => $row) {
            $found = [];
            foreach ($row as $ci => $cell) {
                $k = strtolower(preg_replace('/[^A-Za-z]/', '', (string) $cell));
                $key = match ($k) {
                    'fibreplanid', 'planid' => 'plan_id',
                    'productid'             => 'product',
                    'technology'            => 'technology',
                    'mapping'               => 'mapping',
                    'linespeed'             => 'speed',
                    default                 => null,
                };
                // Left table only: the first column of each name wins ("ProductID" also heads a right-hand table).
                if ($key !== null && ! isset($found[$key])) {
                    $found[$key] = $ci;
                }
            }
            if (isset($found['plan_id'], $found['technology'])) {
                $header = $i;
                $cols   = $found;
                break;
            }
        }
        if ($header === null) {
            throw new RuntimeException('Could not find the header row (a row with "FIBRE PlanID" and "Technology").');
        }

        $rows = [];
        $warnings = [];
        $seen = [];
        foreach (array_slice($table, $header + 1) as $offset => $row) {
            $line = $header + 2 + $offset;
            $get  = fn (string $key) => isset($cols[$key]) ? trim((string) ($row[$cols[$key]] ?? '')) : '';
            $planId = $get('plan_id');
            if ($planId === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $warnings[] = 'Stopped after ' . self::MAX_ROWS . ' rows.';
                break;
            }
            if (! preg_match('/^[A-Za-z0-9._-]{2,30}$/', $planId)) {
                $warnings[] = "Row $line: skipped, \"" . mb_substr($planId, 0, 30) . '" is not a valid Plan ID.';
                continue;
            }
            $product = $get('product');
            if ($product !== '' && strtoupper($product) !== 'FIBRE') {
                $warnings[] = "Row $line: skipped $planId, ProductID \"" . mb_substr($product, 0, 20) . '" is not FIBRE.';
                continue;
            }
            $tech = VocusOptions::normalizeTechnology($get('technology'));
            if ($tech === null || ! in_array($tech, VocusOptions::PLAN_TECHNOLOGIES, true)) {
                $warnings[] = "Row $line: skipped $planId, unknown technology \"" . mb_substr($get('technology'), 0, 20) . '".';
                continue;
            }
            $mapping = VocusOptions::normalizeMapping($get('mapping'));
            if ($mapping === null) {
                $warnings[] = "Row $line: skipped $planId, unknown mapping \"" . mb_substr($get('mapping'), 0, 20) . '".';
                continue;
            }
            if (isset($seen[$planId])) {
                $warnings[] = "Row $line: skipped duplicate Plan ID $planId.";
                continue;
            }
            $seen[$planId] = true;

            $speed  = mb_substr($get('speed'), 0, 40);
            $speeds = VocusOptions::speedsForTier($speed !== '' ? $speed : null);
            $rows[] = [
                'plan_id'    => $planId,
                'technology' => $tech,
                'mapping'    => $mapping,
                'speed_tier' => $speed !== '' ? $speed : null,
                'download'   => $speeds[0] ?? null,
                'upload'     => $speeds[1] ?? null,
            ];
        }

        return ['rows' => $rows, 'warnings' => array_slice($warnings, 0, 50)];
    }

    // ----- plan / apply ------------------------------------------------------------------------

    /** Default name for a new plan: "{Technology} {LineSpeed} ({Mapping})". */
    public static function defaultName(array $row): string
    {
        $tech = $row['technology'] === 'WIRELESS' ? 'Wireless' : $row['technology'];

        return mb_substr(trim($tech . ' ' . ($row['speed_tier'] ?? '') . ' (' . (VocusOptions::PLAN_MAPPINGS[$row['mapping']] ?? $row['mapping']) . ')'), 0, 100);
    }

    /**
     * Compare rows with the database. "onlyMapping" keeps only rows with that mapping (null keeps all).
     *
     * @param list<array<string,mixed>> $rows
     * @return array{new:list<array>,updated:list<array>,unchanged:list<array>,skipped:int}
     */
    public static function plan(array $rows, ?string $onlyMapping): array
    {
        $kept = array_values(array_filter($rows, fn ($r) => $onlyMapping === null || $r['mapping'] === $onlyMapping));
        $existing = VocusPlan::whereIn('plan_id', array_column($kept, 'plan_id'))->get()->keyBy('plan_id');

        $out = ['new' => [], 'updated' => [], 'unchanged' => [], 'skipped' => count($rows) - count($kept)];
        foreach ($kept as $row) {
            $plan = $existing->get($row['plan_id']);
            if (! $plan) {
                $out['new'][] = $row;
            } elseif (self::changes($plan, $row) !== []) {
                $out['updated'][] = $row;
            } else {
                $out['unchanged'][] = $row;
            }
        }

        return $out;
    }

    /**
     * Attribute changes for an existing plan. Speeds only change when the sheet gives a known speed (an unknown named
     * tier never wipes speeds an admin has set).
     *
     * @return array<string,mixed>
     */
    private static function changes(VocusPlan $plan, array $row): array
    {
        $want = ['technology' => $row['technology'], 'mapping' => $row['mapping'], 'speed_tier' => $row['speed_tier']];
        if ($row['download'] !== null && $row['upload'] !== null) {
            $want['download_mbps'] = $row['download'];
            $want['upload_mbps']   = $row['upload'];
        }

        return array_filter($want, fn ($v, $k) => $plan->{$k} !== $v, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Apply the import. Returns the counts.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{new:int,updated:int,unchanged:int,skipped:int}
     */
    public static function apply(array $rows, ?string $onlyMapping): array
    {
        $plan = self::plan($rows, $onlyMapping);

        foreach ($plan['new'] as $row) {
            VocusPlan::create([
                'plan_id'       => $row['plan_id'],
                'name'          => self::defaultName($row),
                'technology'    => $row['technology'],
                'mapping'       => $row['mapping'],
                'speed_tier'    => $row['speed_tier'],
                'download_mbps' => $row['download'],
                'upload_mbps'   => $row['upload'],
                'cost_price'    => '0.00',
                'sell_price'    => '0.00',
                'scopes'        => null,
                'active'        => false,
            ]);
        }
        foreach ($plan['updated'] as $row) {
            $model = VocusPlan::where('plan_id', $row['plan_id'])->first();
            if ($model) {
                $model->update(self::changes($model, $row));
            }
        }

        return ['new' => count($plan['new']), 'updated' => count($plan['updated']), 'unchanged' => count($plan['unchanged']), 'skipped' => $plan['skipped']];
    }
}

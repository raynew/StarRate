<?php

declare(strict_types=1);

namespace OCA\StarRate\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * „In Dateien schreiben“: vergleicht die StarRate-Bewertung (DB) mit dem XMP der
 * Datei (JPEG eingebettet / RAW-Sidecar) und schreibt StarRate-Werte zurück.
 *
 * Wozu: Lightroom mit „Änderungen automatisch in XMP speichern“ überschreibt
 * Sidecars mit seinem Katalogstand. Hat der Katalog die StarRate-Bewertungen
 * noch nicht gelesen, fehlen sie danach in der Datei — und „Metadaten aus Datei
 * lesen“ findet nichts. Hiermit stellt man den StarRate-Stand in den Dateien
 * wieder her.
 *
 * Es werden nur StarRate-Werte geschrieben, nie Werte in der Datei gelöscht:
 * - missing:   StarRate hat einen Wert, die Datei keinen   → wird ergänzt
 * - different: beide haben einen Wert, er weicht ab        → nur mit $overwrite
 * - extra:     nur die Datei hat einen Wert               → bleibt immer stehen
 */
class XmpSyncService
{
    public const FIELDS = ['rating', 'color', 'pick'];

    public const KIND_MISSING   = 'missing';
    public const KIND_DIFFERENT = 'different';
    public const KIND_EXTRA     = 'extra';

    public const RESULT_WRITTEN   = 'written';
    public const RESULT_UNCHANGED = 'unchanged';
    public const RESULT_NO_STORE  = 'no_store';
    public const RESULT_FAILED    = 'failed';

    public function __construct(
        private readonly XmpStoreResolver $xmpStores,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Feldweiser Vergleich DB ↔ Datei. Leer heißt: 0★, kein Label, kein Pick.
     *
     * @param array{rating: int, color: string|null, pick: string} $db
     * @param array{rating: int, label: string|null, pick: string} $xmp
     * @return array<string, array{db: int|string|null, file: int|string|null, kind: string}>
     */
    public static function diff(array $db, array $xmp): array
    {
        $pairs = [
            'rating' => [$db['rating'], $xmp['rating'], 0],
            'color'  => [$db['color'],  $xmp['label'],  null],
            'pick'   => [$db['pick'],   $xmp['pick'],   'none'],
        ];

        $diffs = [];
        foreach ($pairs as $field => [$dbVal, $fileVal, $empty]) {
            if ($dbVal === $fileVal) {
                continue;
            }
            $kind = match (true) {
                $fileVal === $empty => self::KIND_MISSING,
                $dbVal === $empty   => self::KIND_EXTRA,
                default             => self::KIND_DIFFERENT,
            };
            $diffs[$field] = ['db' => $dbVal, 'file' => $fileVal, 'kind' => $kind];
        }
        return $diffs;
    }

    /**
     * Welche Felder ein Schreiblauf anfassen würde.
     *
     * @param array<string, array{kind: string}> $diffs
     * @param list<string> $fields
     * @return list<string>
     */
    public static function fieldsToWrite(array $diffs, array $fields, bool $overwrite): array
    {
        $out = [];
        foreach ($diffs as $field => $d) {
            if (!in_array($field, $fields, true)) {
                continue;
            }
            if ($d['kind'] === self::KIND_MISSING || ($overwrite && $d['kind'] === self::KIND_DIFFERENT)) {
                $out[] = $field;
            }
        }
        return $out;
    }

    /**
     * Liest das XMP der Datei und vergleicht es mit $db.
     *
     * @param array{rating: int, color: string|null, pick: string} $db
     * @return array{status: string, diffs: array<string, array{db: mixed, file: mixed, kind: string}>}
     *         status: ok | no_store (kein XMP-Ziel, z.B. PNG/DNG oder Einstellung aus) | unreadable
     */
    public function compare(File $file, array $db, array $settings): array
    {
        $store = $this->xmpStores->forFile($file, $settings);
        if ($store === null) {
            return ['status' => self::RESULT_NO_STORE, 'diffs' => []];
        }
        try {
            $xmp = $store->readMetadata($file);
        } catch (\Exception $e) {
            $this->logger->info("StarRate xmp-sync: cannot read XMP of {$file->getId()}: " . $e->getMessage());
            return ['status' => 'unreadable', 'diffs' => []];
        }
        return ['status' => 'ok', 'diffs' => self::diff($db, $xmp)];
    }

    /**
     * Schreibt die StarRate-Werte der gewählten Felder in die Datei — nur fehlende,
     * mit $overwrite auch abweichende. Der Vergleich wird hier neu gemacht (nicht
     * dem Client geglaubt), damit nie mehr geschrieben wird als die Vorschau zeigt.
     *
     * @param array{rating: int, color: string|null, pick: string} $db
     * @param list<string> $fields
     */
    public function apply(File $file, array $db, array $settings, array $fields, bool $overwrite): string
    {
        $store = $this->xmpStores->forFile($file, $settings);
        if ($store === null) {
            return self::RESULT_NO_STORE;
        }
        try {
            $diffs   = self::diff($db, $store->readMetadata($file));
            $toWrite = self::fieldsToWrite($diffs, $fields, $overwrite);
            if ($toWrite === []) {
                return self::RESULT_UNCHANGED;
            }
            $store->writeMetadata(
                $file,
                in_array('rating', $toWrite, true) ? $db['rating'] : null,
                in_array('color', $toWrite, true)  ? ($db['color'] ?? '') : null,
                in_array('pick', $toWrite, true)   ? $db['pick'] : null,
                $settings['xmp_label_language'] ?? XmpService::LABEL_LANG_EN,
            );
            return self::RESULT_WRITTEN;
        } catch (\Exception $e) {
            $this->logger->warning("StarRate xmp-sync: write failed for {$file->getId()}: " . $e->getMessage());
            return self::RESULT_FAILED;
        }
    }
}

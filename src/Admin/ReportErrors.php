<?php
declare(strict_types=1);

namespace EducationalCurriculum\Admin;

use EducationalCurriculum\Sync\Plan;

/**
 * Texts of the blocking errors of a report.
 */
class ReportErrors
{
    /**
     * @param array $error An error of the report: code, identifier, item and detail
     * @return array [translatable template, arguments]
     */
    public static function message(array $error): array
    {
        $identifier = $error['identifier'];
        $detail = $error['detail'];
        switch ($error['code']) {
            case Plan::ERROR_UNKNOWN_IDENTIFIER:
                return [
                    'The item %s is in the curriculum item set but not in the release.', // @translate
                    [$identifier],
                ];
            case Plan::ERROR_CLASS_MISMATCH:
                return [
                    'The item %s has the class %s, but its node is a %s.', // @translate
                    [$identifier, $detail['current'], $detail['expected']],
                ];
            case Plan::ERROR_UNRETIRED:
                return [
                    'The item %s is retired in Omeka but current in the release.', // @translate
                    [$identifier],
                ];
            case Plan::ERROR_DUPLICATE_IDENTIFIER:
                return [
                    'Several items of the curriculum item set have the identifier %s.', // @translate
                    [$identifier],
                ];
            case Plan::ERROR_MISSING_IDENTIFIER:
                return [
                    'An item of the curriculum item set has no literal dcterms:identifier.', // @translate
                    [],
                ];
            case Plan::ERROR_UNKNOWN_LINK:
                return [
                    'The node %s links through %s to %s, which is not in the release.', // @translate
                    [$identifier, $detail['property'], $detail['target']],
                ];
            case Plan::ERROR_VOCABULARY_TERM_REMOVED:
                return [
                    'The new cur.ttl removes %s, which items use.', // @translate
                    [$detail['term']],
                ];
            case Plan::ERROR_VOCABULARY_TERM_MISSING:
                return [
                    'The release uses %s, which its cur.ttl does not define.', // @translate
                    [$detail['term']],
                ];
            default:
                return [
                    'Unknown error %s.', // @translate
                    [$error['code']],
                ];
        }
    }
}

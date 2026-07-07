<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Controller\Admin;

use Marcostastny\SuluAIBundle\Entity\AiSetting;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryGate;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\DataQueryRunner;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\InvalidSelectQueryException;
use Marcostastny\SuluAIBundle\Service\Assistant\DataQuery\SelectQueryValidator;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSV export for assistant query-result cards. The SQL comes back from the
 * client, so it is revalidated with the same validator (and the same
 * permission) as the chat - the user can only export what the chat could
 * have queried, just with a larger row cap and untruncated cells.
 */
class DataQueryExportController
{
    private const MAX_EXPORT_ROWS = 10000;

    public function __construct(
        private SecurityCheckerInterface $securityChecker,
        private DataQueryGate $gate,
        private SelectQueryValidator $validator,
        private DataQueryRunner $runner,
    ) {
    }

    public function postAction(Request $request): Response
    {
        $this->securityChecker->checkPermission(AiSetting::SECURITY_CONTEXT_DATA_QUERY, PermissionTypes::VIEW);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            $data = [];
        }
        $sql = (string) ($data['sql'] ?? '');

        $tables = $this->gate->tables();
        if ([] === $tables) {
            return new JsonResponse(['message' => 'Data queries are not enabled.'], 400);
        }

        try {
            $validated = $this->validator->validate($sql, $tables, self::MAX_EXPORT_ROWS);
        } catch (InvalidSelectQueryException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 400);
        }

        try {
            $result = $this->runner->run($validated, self::MAX_EXPORT_ROWS);
        } catch (\Throwable) {
            return new JsonResponse(['message' => 'The query failed.'], 400);
        }

        $handle = \fopen('php://temp', 'r+');
        if (false === $handle) {
            return new JsonResponse(['message' => 'The export failed.'], 500);
        }
        // UTF-8 BOM so Excel detects the encoding. Explicit escape '' keeps
        // RFC-4180 quoting and avoids the PHP 8.4 default-change deprecation.
        \fwrite($handle, "\xEF\xBB\xBF");
        \fputcsv($handle, $result['columns'], ',', '"', '');
        foreach ($result['rows'] as $row) {
            \fputcsv($handle, \array_map(static fn (?string $value): string => (string) $value, $row), ',', '"', '');
        }
        \rewind($handle);
        $csv = (string) \stream_get_contents($handle);
        \fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="query-export.csv"',
        ]);
    }
}

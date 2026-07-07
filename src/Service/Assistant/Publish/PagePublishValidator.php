<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant\Publish;

use Marcostastny\SuluAIBundle\Service\Assistant\NavigationTargetCollector;

/**
 * Validates a propose_publish tool call and assembles the publishPage action
 * for the approval card. The target is either the currently open page (no
 * "id" argument) or a pages result from search_content in the same turn, so
 * the model can never invent page ids; the target webspace must grant Sulu's
 * LIVE permission.
 */
class PagePublishValidator
{
    public function __construct(
        private NavigationTargetCollector $targetCollector,
        private PagePublishGate $publishGate,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments raw tool-call arguments
     * @param array{id: string, locale: string, webspace: string, title: string}|null $contextPage the currently
     *                                                                                             open page, if any
     *
     * @return array{action: array<string, mixed>}|array{errors: list<string>}
     */
    public function validate(array $arguments, ?array $contextPage): array
    {
        $mode = \trim((string) ($arguments['mode'] ?? ''));
        if (!\in_array($mode, ['publish', 'unpublish'], true)) {
            return ['errors' => ['mode must be "publish" or "unpublish".']];
        }

        $id = \trim((string) ($arguments['id'] ?? ''));

        if ('' === $id) {
            if (null === $contextPage) {
                return ['errors' => ['No page is open. Pass the id and locale of a pages result from search_content, or ask the user to open the page first.']];
            }
            $target = $contextPage;
        } else {
            $locale = \trim((string) ($arguments['locale'] ?? ''));
            $known = $this->targetCollector->get('pages', $id, $locale);
            if (null === $known) {
                return ['errors' => [\sprintf(
                    'page %s (locale %s) was not returned by search_content in this turn. Call search_content to find the page first, or omit "id" to target the currently open page.',
                    $id,
                    $locale
                )]];
            }
            $webspace = $known['attributes']['webspace'] ?? null;
            $target = [
                'id' => $known['id'],
                'locale' => $known['locale'],
                'webspace' => \is_string($webspace) ? $webspace : '',
                'title' => $known['title'],
            ];
        }

        if ('' === $target['webspace']) {
            return ['errors' => ['The target webspace could not be determined. Find the page via search_content and pass its id and locale.']];
        }

        if (!$this->publishGate->allowsWebspace($target['webspace'])) {
            return ['errors' => [\sprintf('You are not allowed to publish in webspace "%s".', $target['webspace'])]];
        }

        return ['action' => [
            'type' => 'publishPage',
            'mode' => $mode,
            'id' => $target['id'],
            'title' => $target['title'],
            'locale' => $target['locale'],
            'webspace' => $target['webspace'],
            'message' => (string) ($arguments['message'] ?? ''),
            'resume' => (bool) ($arguments['resume'] ?? false),
        ]];
    }
}

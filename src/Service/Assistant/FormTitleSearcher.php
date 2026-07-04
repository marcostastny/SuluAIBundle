<?php

declare(strict_types=1);

namespace Marcostastny\SuluAIBundle\Service\Assistant;

use Doctrine\ORM\EntityManagerInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;

/**
 * Finds sulu/form-bundle forms by translated title. Forms are not part of the
 * SEAL admin index, so they get their own Doctrine lookup. Degrades to empty
 * results when the form bundle is not installed or the user lacks permission.
 */
class FormTitleSearcher
{
    private const TRANSLATION_CLASS = 'Sulu\Bundle\FormBundle\Entity\FormTranslation';
    private const SECURITY_CONTEXT = 'sulu.form.forms';
    private const EDIT_VIEW = 'sulu_form.edit_form';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SecurityCheckerInterface $securityChecker,
    ) {
    }

    public function isAvailable(): bool
    {
        return \class_exists(self::TRANSLATION_CLASS);
    }

    /**
     * @return list<array{type: string, id: string, locale: string, title: string, view: string, attributes: array<string, mixed>}>
     */
    public function search(string $query, ?string $locale, int $limit): array
    {
        if (!$this->isAvailable()
            || !$this->securityChecker->hasPermission(self::SECURITY_CONTEXT, PermissionTypes::EDIT)
        ) {
            return [];
        }

        $builder = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(self::TRANSLATION_CLASS, 't')
            ->where('LOWER(t.title) LIKE :query')
            ->setParameter('query', '%' . \mb_strtolower($query) . '%')
            ->setMaxResults($limit);

        if (null !== $locale && '' !== $locale) {
            $builder->andWhere('t.locale = :locale')->setParameter('locale', $locale);
        }

        $results = [];

        /** @var \Sulu\Bundle\FormBundle\Entity\FormTranslation $translation */
        foreach ($builder->getQuery()->getResult() as $translation) {
            $id = $translation->getForm()->getId();
            $results[] = [
                'type' => 'forms',
                'id' => (string) $id,
                'locale' => $translation->getLocale(),
                'title' => $translation->getTitle(),
                'view' => self::EDIT_VIEW,
                'attributes' => ['id' => $id, 'locale' => $translation->getLocale()],
            ];
        }

        return $results;
    }
}

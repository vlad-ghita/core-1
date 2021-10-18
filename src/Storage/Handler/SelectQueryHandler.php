<?php

declare(strict_types=1);

namespace Bolt\Storage\Handler;

use Bolt\Entity\Content;
use Bolt\Storage\ContentQueryParser;
use Bolt\Storage\Directive\LimitDirective;
use Bolt\Storage\Directive\PageDirective;
use Bolt\Storage\SelectQuery;
use Doctrine\ORM\Query;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\Request;

/**
 *  Handler class to perform select query and return a resultset.
 */
class SelectQueryHandler
{
    /**
     * @return Content|Pagerfanta|null
     */
    public function __invoke(ContentQueryParser $contentQuery)
    {
        $repo = $contentQuery->getContentRepository();
        $qb = $repo->getQueryBuilder();

        /** @var SelectQuery $selectQuery */
        $selectQuery = $contentQuery->getService('select');

        // Note: This might seem superfluous, but if we "re-use" $contentQuery,
        // this needs resetting.
        $selectQuery->setSingleFetchMode(null);

        $selectQuery->setQueryBuilder($qb);
        $selectQuery->setContentTypeFilter($contentQuery->getContentTypes());
        $selectQuery->setParameters($contentQuery->getParameters());

        $contentQuery->runScopes($selectQuery);

        // This is required. Not entirely sure why.
        $selectQuery->build();

        // Bolt4 introduces an extra table for field values, so additional
        // joins are required.
        $selectQuery->doReferenceJoins();
        $selectQuery->doTaxonomyJoins();
        $selectQuery->doFieldJoins();

        $contentQuery->runDirectives($selectQuery);

        if ($selectQuery->shouldReturnSingle()) {
            return $qb
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        $query = $qb->getQuery();

        $amountPerPage = (int) $contentQuery->getDirective(LimitDirective::NAME);
        $page = $contentQuery->getDirective(PageDirective::NAME);
        if ($page !== null) {
            $page = (int) $page;
        }

        $request = $contentQuery->getRequest();

        return $this->createPaginator($request, $query, $amountPerPage, $page);
    }

    private function createPaginator(?Request $request, Query $query, int $amountPerPage, ?int $page = null): Pagerfanta
    {
        $paginator = new Pagerfanta(new DoctrineORMAdapter($query, true, true));
        $paginator->setMaxPerPage($amountPerPage);

        $dql = $query->getDQL();
        dump($dql);

        $dql = <<<START
            SELECT content
            FROM Bolt\Entity\Content content
            LEFT JOIN content.fields fields_fruits
            LEFT JOIN fields_fruits.translations translations_fruits
            WHERE content.contentType = :ct0
                AND (((JSON_ARRAY(translations_fruits.value) = :fruits_1
                OR JSON_ARRAY(translations_fruits.value) = :fruits_2)
                AND fields_fruits.parent IS NULL
                AND fields_fruits.name = :field_fruits)
                AND content.status = :status_1) ORDER BY content.publishedAt DESC
START;

        $dql = <<<START
            SELECT content
            FROM Bolt\Entity\Content content
            LEFT JOIN content.fields fields_fruits
            LEFT JOIN fields_fruits.translations translations_fruits
            WHERE content.contentType = :ct0
                AND (:fruits_1 IN (JSON_ARRAY(translations_fruits.value))
                AND fields_fruits.parent IS NULL
                AND fields_fruits.name = :field_fruits)
                AND content.status = :status_1) ORDER BY content.publishedAt DESC
START;

//        $query->setDQL($dql);

//        dd($query->getSQL());

//        die;

        // If current page was not set explicitly.
        if ($page === null) {
            // If we don't have $request, we're likely not in a web context.
            if ($request) {
                $page = (int) $request->get('page', 1);
            } else {
                $page = 1;
            }
        }

        // If we have multiple pagers on page, we shouldn't allow one of the
        // pagers to go over the maximum, thereby throwing an exception. In this
        // case, this specific pager show stay on the last page.
        $paginator->setCurrentPage(min($page, $paginator->getNbPages()));

        return $paginator;
    }
}

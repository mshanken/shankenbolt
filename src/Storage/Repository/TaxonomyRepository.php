<?php

namespace Bolt\Storage\Repository;

use Bolt\Events\QueryEvent;
use Bolt\Events\QueryEvents;
use Bolt\Exception\InvalidRepositoryException;
use Bolt\Storage\Query\TaxonomyQueryResultset;
use Bolt\Storage\Repository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

class TaxonomyRepository extends Repository
{
    /**
     * @param array $contentTypes
     * @param array $taxonomyTypes
     *
     * @throws InvalidRepositoryException
     *
     * @return QueryBuilder
     */
    public function queryContentByTaxonomy($contentTypes, $taxonomyTypes)
    {
        $query = $this->createQueryBuilder();
        $this->buildJoin($query, $contentTypes);
        $this->buildWhere($query, $taxonomyTypes);

        return $query;
    }

    /**
     * @param QueryBuilder $query
     *
     * @return TaxonomyQueryResultset
     */
    public function getContentByTaxonomy(QueryBuilder $query)
    {
        $results = $query->execute()->fetchAll();
        $set = new TaxonomyQueryResultset();
        $set->setEntityManager($this->getEntityManager());
        $set->add($results);
        $set->setOriginalQuery('getcontent', $query);
        $executeEvent = new QueryEvent($query, $set);
        $this->getEntityManager()->getEventManager()->dispatch(QueryEvents::EXECUTE, $executeEvent);

        return $set;
    }

    /**
     * Look up the stored display names for existing taxonomy slugs.
     *
     * Rows whose name is the slug itself are ignored, so a tag that was saved
     * as "Napa Valley" resolves to that rather than "napa-valley". When a slug
     * has several names, the most-used one wins.
     *
     * @param string $taxonomyType
     * @param array  $slugs
     *
     * @return array slug => name
     */
    public function getNamesForSlugs($taxonomyType, array $slugs)
    {
        if (empty($slugs)) {
            return [];
        }

        $query = $this->em->createQueryBuilder()
            ->select('slug, name, COUNT(*) AS uses')
            ->from($this->getTableName())
            ->where('taxonomytype = :taxonomytype')
            ->andWhere('slug IN (:slugs)')
            ->andWhere('name <> slug')
            ->groupBy('slug, name')
            ->orderBy('uses', 'DESC')
            ->setParameter('taxonomytype', $taxonomyType)
            ->setParameter('slugs', array_values($slugs), Connection::PARAM_STR_ARRAY);

        $names = [];
        foreach ($query->execute()->fetchAll() as $row) {
            if (!isset($names[$row['slug']])) {
                $names[$row['slug']] = $row['name'];
            }
        }

        return $names;
    }

    /**
     * @param QueryBuilder $query
     * @param array        $contentTypes
     *
     * @throws InvalidRepositoryException
     */
    protected function buildJoin(QueryBuilder $query, $contentTypes)
    {
        $subQuery = '(SELECT ';
        $fragments = [];
        foreach ($contentTypes as $content) {
            $repo = $this->getEntityManager()->getRepository($content);
            $table = $repo->getTableName();
            $fragments[] = "id,status, '$content' AS tablename FROM " . $table;
        }
        $subQuery .= join(' UNION SELECT ', $fragments);
        $subQuery .= ')';

        $query->from($subQuery, 'content');
        $query->addSelect('content.*');
    }

    /**
     * @param QueryBuilder $query
     * @param array        $taxonomyTypes
     */
    protected function buildWhere(QueryBuilder $query, $taxonomyTypes)
    {
        $params = [];
        $i = 0;
        $where = $query->expr()->andX();
        foreach ($taxonomyTypes as $name => $slug) {
            $where->add($query->expr()->eq('taxonomy.taxonomytype', ':taxonomytype_' . $i));
            $where->add($query->expr()->eq('taxonomy.slug', ':slug_' . $i));
            $params['taxonomytype_' . $i] = $name;
            $params['slug_' . $i] = $slug;
            $i++;
        }
        $query->where($where)->setParameters($params);
        $query->andWhere("content.status='published'");
        $query->andWhere('taxonomy.contenttype=content.tablename');
        $query->andWhere('taxonomy.content_id=content.id');
    }
}

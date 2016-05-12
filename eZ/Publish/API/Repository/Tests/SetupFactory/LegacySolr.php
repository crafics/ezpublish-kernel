<?php
/**
 * File containing the Test Setup Factory base class
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 * @version //autogentag//
 */

namespace eZ\Publish\API\Repository\Tests\SetupFactory;

use eZ\Publish\Core\Base\ServiceContainer;
use eZ\Publish\Core\Base\Container\Compiler;
use PDO;

/**
 * A Test Factory is used to setup the infrastructure for a tests, based on a
 * specific repository implementation to test.
 */
class LegacySolr extends Legacy
{
    /**
     * Returns a configured repository for testing.
     *
     * @param bool $initializeFromScratch
     *
     * @return \eZ\Publish\API\Repository\Repository
     */
    public function getRepository( $initializeFromScratch = true )
    {
        // Load repository first so all initialization steps are done
        $repository = parent::getRepository( $initializeFromScratch );

        if ( $initializeFromScratch )
        {
            $this->indexAll();
        }

        return $repository;
    }

    protected function getServiceContainer()
    {
        if ( !isset( self::$serviceContainer ) )
        {
            $config = include __DIR__ . "/../../../../../../config.php";
            $installDir = $config['install_dir'];

            /** @var \Symfony\Component\DependencyInjection\ContainerBuilder $containerBuilder */
            $containerBuilder = include $config['container_builder_path'];

            /** @var \Symfony\Component\DependencyInjection\Loader\YamlFileLoader $loader */
            $loader->load( 'tests/integration_legacy_solr.yml' );

            $containerBuilder->addCompilerPass( new Compiler\Storage\Solr\AggregateCriterionVisitorPass() );
            $containerBuilder->addCompilerPass( new Compiler\Storage\Solr\AggregateFacetBuilderVisitorPass() );
            $containerBuilder->addCompilerPass( new Compiler\Storage\Solr\AggregateFieldValueMapperPass() );
            $containerBuilder->addCompilerPass( new Compiler\Storage\Solr\AggregateSortClauseVisitorPass() );
            $containerBuilder->addCompilerPass( new Compiler\Storage\Solr\FieldRegistryPass() );
            $containerBuilder->addCompilerPass( new Compiler\Storage\Solr\SignalSlotPass() );

            $containerBuilder->setParameter(
                "legacy_dsn",
                self::$dsn
            );

            $containerBuilder->setParameter(
                "io_root_dir",
                self::$ioRootDir . '/' . $containerBuilder->getParameter( 'storage_dir' )
            );

            self::$serviceContainer = new ServiceContainer(
                $containerBuilder,
                $installDir,
                $config['cache_dir'],
                true,
                true
            );
        }

        return self::$serviceContainer;
    }

    /**
     * Indexes all Content objects.
     */
    protected function indexAll()
    {
        // @todo: Is there a nicer way to get access to all content objects? We
        // require this to run a full index here.
        /** @var \eZ\Publish\SPI\Persistence\Handler $persistenceHandler */
        $persistenceHandler = $this->getServiceContainer()->get( 'ezpublish.spi.persistence.legacy_solr' );
        /** @var \eZ\Publish\Core\Persistence\Database\DatabaseHandler $databaseHandler */
        $databaseHandler = $this->getServiceContainer()->get( 'ezpublish.api.storage_engine.legacy.dbhandler' );

        $query = $databaseHandler
            ->createSelectQuery()
            ->select( 'id', 'current_version' )
            ->from( 'ezcontentobject' );

        $stmt = $query->prepare();
        $stmt->execute();

        $contentObjects = array();
        while ( $row = $stmt->fetch( PDO::FETCH_ASSOC ) )
        {
            $contentObjects[] = $persistenceHandler->contentHandler()->load(
                $row['id'],
                $row['current_version']
            );
        }

        /** @var \eZ\Publish\Core\Persistence\Solr\Content\Search\Handler $searchHandler */
        $searchHandler = $persistenceHandler->searchHandler();
        $searchHandler->setCommit( false );
        $searchHandler->purgeIndex();
        $searchHandler->setCommit( true );
        $searchHandler->bulkIndexContent( $contentObjects );
    }
}

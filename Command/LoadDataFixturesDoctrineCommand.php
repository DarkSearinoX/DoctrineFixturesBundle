<?php


namespace Doctrine\Bundle\FixturesBundle\Command;

use Doctrine\Bundle\DoctrineBundle\Command\DoctrineCommand;
use Doctrine\Bundle\FixturesBundle\Interfaces\OrderedFixtureInterface;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\DBAL\Sharding\PoolingShardConnection;
use InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Load data fixtures from bundles.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Jonathan H. Wage <jonwage@gmail.com>
 */
class LoadDataFixturesDoctrineCommand extends DoctrineCommand
{
    private $fixturesLoader;

    public function __construct(SymfonyFixturesLoader $fixturesLoader)
    {
        parent::__construct();

        $this->fixturesLoader = $fixturesLoader;
    }

    protected function configure()
    {
        $this
            ->setName('doctrine:fixtures:load')
            ->setDescription('Load data fixtures to your database')
            ->addOption('append', null, InputOption::VALUE_NONE, 'Append the data fixtures instead of deleting all data from the database first.')
            ->addOption('em', null, InputOption::VALUE_REQUIRED, 'The entity manager to use for this command.')
            ->addOption('shard', null, InputOption::VALUE_REQUIRED, 'The shard connection to use for this command.')
            ->addOption('fixtures', null, InputOption::VALUE_REQUIRED, 'Loads from dir.')
            ->addOption('purge-with-truncate', null, InputOption::VALUE_NONE, 'Purge data by using a database-level TRUNCATE statement')
            ->setHelp(<<<EOT
The <info>%command.name%</info> command loads data fixtures from your application:

  <info>php %command.full_name%</info>

Fixtures are services that are tagged with <comment>doctrine.fixture.orm</comment>.

If you want to append the fixtures instead of flushing the database first you can use the <comment>--append</comment> option:

  <info>php %command.full_name%</info> <comment>--append</comment>

By default Doctrine Data Fixtures uses DELETE statements to drop the existing rows from the database.
If you want to use a TRUNCATE statement instead you can use the <comment>--purge-with-truncate</comment> flag:

  <info>php %command.full_name%</info> <comment>--purge-with-truncate</comment>

EOT
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ui = new SymfonyStyle($input, $output);

        /** @var $doctrine \Doctrine\Common\Persistence\ManagerRegistry */
        $doctrine = $this->getContainer()->get('doctrine');
        $em = $doctrine->getManager($input->getOption('em'));

        if (!$input->getOption('append')) {
            $ui->ask('Careful, database will be purged. Do you want to continue y/N ?', false);
        }

        if ($input->getOption('shard')) {
            if (!$em->getConnection() instanceof PoolingShardConnection) {
                throw new \LogicException(sprintf(
                    'Connection of EntityManager "%s" must implement shards configuration.',
                    $input->getOption('em')
                ));
            }

            $em->getConnection()->connect($input->getOption('shard'));
        }

        if($input->getOption('fixtures')){
            $fixtures = $this->fixturesLoader->loadFromDirectory($input->getOption('fixtures'));
            if (!$fixtures) {
                throw new InvalidArgumentException(
                    'Could not find any fixture services to load.'
                );
            }

            $fixtures = $this->orderFixturesByNumber($fixtures);
        }else{
            $fixtures = $this->fixturesLoader->getFixtures();
            if (!$fixtures) {
                throw new InvalidArgumentException(
                    'Could not find any fixture services to load.'
                );
            }
        }
        $purger = new ORMPurger($em);
        $purger->setPurgeMode($input->getOption('purge-with-truncate') ? ORMPurger::PURGE_MODE_TRUNCATE : ORMPurger::PURGE_MODE_DELETE);
        $executor = new ORMExecutor($em, $purger);
        $executor->setLogger(function ($message) use ($ui) {
            $ui->text(sprintf('  <comment>></comment> <info>%s</info>', $message));
        });
        $executor->execute($fixtures, $input->getOption('append'));
    }

    /**
     * Orders fixtures by number
     *
     * @return array
     */
    private function orderFixturesByNumber($fixtures)
    {
        $orderedFixtures = $fixtures;
        usort($orderedFixtures, function($a, $b) {
            if ($a instanceof \Doctrine\Common\DataFixtures\OrderedFixtureInterface && $b instanceof \Doctrine\Common\DataFixtures\OrderedFixtureInterface) {
                if ($a->getOrder() === $b->getOrder()) {
                    return 0;
                }
                return $a->getOrder() < $b->getOrder() ? -1 : 1;
            } elseif ($a instanceof OrderedFixtureInterface) {
                return $a->getOrder() === 0 ? 0 : 1;
            } elseif ($b instanceof OrderedFixtureInterface) {
                return $b->getOrder() === 0 ? 0 : -1;
            }
            return 0;
        });

        return $orderedFixtures;
    }
}


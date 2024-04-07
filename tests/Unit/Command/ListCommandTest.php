<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Command;

use Closure;
use Cron\CronExpression;
use DateTimeZone;
use Generator;
use Orisai\Clock\FrozenClock;
use Orisai\Scheduler\Command\ListCommand;
use Orisai\Scheduler\Job\CallbackJob;
use Orisai\Scheduler\SimpleScheduler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Orisai\Scheduler\Doubles\CallbackList;
use Tests\Orisai\Scheduler\Helpers\CommandOutputHelper;
use function putenv;

/**
 * @runTestsInSeparateProcesses
 */
final class ListCommandTest extends TestCase
{

	public function testNoJobs(): void
	{
		$scheduler = new SimpleScheduler();

		$command = new ListCommand($scheduler);
		$tester = new CommandTester($command);

		$tester->execute([]);

		self::assertSame(
			<<<'MSG'
No scheduled jobs have been defined.

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	public function testList(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('* * * * *'),
			'b',
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('*/30 7-15 * * 1-5'),
			'10',
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('* * * 4 *'),
			'a',
		);
		$scheduler->addJob(
			new CallbackJob($cbs->getClosure()),
			new CronExpression('30 * 12 10 *'),
			'1',
		);

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([]);

		self::assertSame(
			<<<'MSG'
  30 * 12 10 *      [1] tests/Doubles/CallbackList.php:32... Next Due: 9 months
  */30 7-15 * * 1-5 [10] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() Next Due: 5 hours
  * * * 4 *         [a] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 2 months
  * * * * *         [b] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() Next Due: 59 seconds

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());

		putenv('COLUMNS=110');
		$tester->execute([]);

		self::assertSame(
			<<<'MSG'
  30 * 12 10 *      [1] tests/Doubles/CallbackList.php:32................................. Next Due: 9 months
  */30 7-15 * * 1-5 [10] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()............... Next Due: 5 hours
  * * * 4 *         [a] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke()........... Next Due: 2 months
  * * * * *         [b] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()............. Next Due: 59 seconds

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());

		putenv('COLUMNS=120');
		$tester->execute([], [
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]);

		self::assertSame(
			<<<'MSG'
  30 * 12 10 *      [1] tests/Doubles/CallbackList.php:32.......................... Next Due: 1970-10-12 00:30:00 +01:00
  */30 7-15 * * 1-5 [10] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()....... Next Due: 1970-01-01 07:00:00 +01:00
  * * * 4 *         [a] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke().... Next Due: 1970-04-01 00:00:00 +01:00
  * * * * *         [b] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()........ Next Due: 1970-01-01 01:01:00 +01:00

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	public function testListWithSeconds(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('* * * * *'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('*/30 7-15 * * 1-5'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('*/30 7-15 * * 1-5'),
			null,
			1,
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('* * * 4 *'),
			null,
			5,
		);
		$scheduler->addJob(
			new CallbackJob($cbs->getClosure()),
			new CronExpression('30 * 12 10 *'),
			null,
			30,
		);

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=120');
		$tester->execute([], [
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]);

		self::assertSame(
			<<<'MSG'
  * * * * *             [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1().... Next Due: 1970-01-01 01:01:00 +01:00
  */30 7-15 * * 1-5     [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1().... Next Due: 1970-01-01 07:00:00 +01:00
  */30 7-15 * * 1-5 / 1 [2] Tests\Orisai\Scheduler\Doubles\CallbackList::job1().... Next Due: 1970-01-01 07:00:00 +01:00
  * * * 4 * / 5         [3] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-04-01 00:00:00 +01:00
  30 * 12 10 * / 30     [4] tests/Doubles/CallbackList.php:32...................... Next Due: 1970-10-12 00:30:00 +01:00

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	public function testNext(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$job = new CallbackJob(Closure::fromCallable([$cbs, 'job1']));
		$scheduler->addJob($job, new CronExpression('* * * 2 *'));
		$scheduler->addJob($job, new CronExpression('* * 3 * *'));
		$scheduler->addJob($job, new CronExpression('* 3 * * *'));
		$scheduler->addJob($job, new CronExpression('2 * * * *'));
		$scheduler->addJob($job, new CronExpression('* * * * *'));
		$scheduler->addJob($job, new CronExpression('* * * * *'), null, 1);
		$scheduler->addJob($job, new CronExpression('* * * * *'));

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=100');
		$tester->execute([
			'--next' => null,
		]);

		self::assertSame(
			<<<'MSG'
  * * * * * / 1 [5] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()......... Next Due: 1 second
  * * * * *     [4] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()....... Next Due: 59 seconds
  * * * * *     [6] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()....... Next Due: 59 seconds
  2 * * * *     [3] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()......... Next Due: 1 minute
  * 3 * * *     [2] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()........... Next Due: 1 hour
  * * 3 * *     [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()............ Next Due: 1 day
  * * * 2 *     [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1().......... Next Due: 1 month

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());

		$tester->execute([
			'--next' => '4',
		]);

		self::assertSame(
			<<<'MSG'
  * * * * * / 1 [5] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()......... Next Due: 1 second
  * * * * *     [4] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()....... Next Due: 59 seconds
  * * * * *     [6] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()....... Next Due: 59 seconds
  2 * * * *     [3] Tests\Orisai\Scheduler\Doubles\CallbackList::job1()......... Next Due: 1 minute

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	public function testNextOverlap(): void
	{
		$clock = new FrozenClock(59, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$job = new CallbackJob(Closure::fromCallable([$cbs, 'job1']));
		$scheduler->addJob($job, new CronExpression('* * * * *'), null, 1);

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=100');
		$tester->execute([], [
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]);

		self::assertSame(
			<<<'MSG'
  * * * * * / 1 [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() Next Due: 1970-01-01 01:01:00 +01:00

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @dataProvider provideInputError
	 */
	public function testInputError(array $input, string $output): void
	{
		$scheduler = new SimpleScheduler();

		$command = new ListCommand($scheduler);
		$tester = new CommandTester($command);

		$tester->execute($input);

		self::assertSame($output, CommandOutputHelper::getCommandOutput($tester));
		self::assertSame($command::FAILURE, $tester->getStatusCode());
	}

	public function provideInputError(): Generator
	{
		yield [
			[
				'--next' => 'not-a-number',
			],
			<<<'MSG'
Option --next expects an int<1, max>, 'not-a-number' given.

MSG,
		];

		yield [
			[
				'--next' => '1.0',
			],
			<<<'MSG'
Option --next expects an int<1, max>, '1.0' given.

MSG,
		];

		yield [
			[
				'--next' => '0',
			],
			<<<'MSG'
Option --next expects an int<1, max>, '0' given.

MSG,
		];

		yield [
			[
				'--timezone' => 'bad-timezone',
			],
			<<<'MSG'
Option --timezone expects a valid timezone, 'bad-timezone' given.

MSG,
		];

		yield [
			[
				'--explain' => 'noop',
			],
			<<<'MSG'
Option --explain expects no value or one of supported languages, 'noop' given. Use --help to list available languages.

MSG,
		];

		yield [
			[
				'--next' => '0',
				'--timezone' => 'bad-timezone',
				'--explain' => 'noop',
			],
			<<<'MSG'
Option --next expects an int<1, max>, '0' given.
Option --timezone expects a valid timezone, 'bad-timezone' given.
Option --explain expects no value or one of supported languages, 'noop' given. Use --help to list available languages.

MSG,
		];
	}

	public function testTimeZone(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('UTC'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('0 1 * * *'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('0 1 * * *'),
			null,
			0,
			new DateTimeZone('Europe/Prague'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('0 1 * * *'),
			null,
			0,
			new DateTimeZone('Australia/Sydney'),
		);

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([], [
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]);

		self::assertSame(
			<<<'MSG'
  0 1 * * *                    [0] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-01-01 01:00:00 +00:00
  0 1 * * * (Europe/Prague)    [1] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-01-01 01:00:00 +00:00
  0 1 * * * (Australia/Sydney) [2] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-01-01 01:00:00 +00:00

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());

		$tester->execute([
			'--timezone' => 'Europe/Prague',
		], [
			'verbosity' => OutputInterface::VERBOSITY_VERBOSE,
		]);

		self::assertSame(
			<<<'MSG'
  0 1 * * * (UTC)              [0] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-01-02 01:00:00 +01:00
  0 1 * * *                    [1] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-01-02 01:00:00 +01:00
  0 1 * * * (Australia/Sydney) [2] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1970-01-02 01:00:00 +01:00

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

	public function testExplain(): void
	{
		$clock = new FrozenClock(1, new DateTimeZone('Europe/Prague'));
		$scheduler = new SimpleScheduler(null, null, null, $clock);

		$cbs = new CallbackList();
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('* * * * *'),
			null,
			0,
			new DateTimeZone('Europe/Prague'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable([$cbs, 'job1'])),
			new CronExpression('*/30 7-15 * * 1-5'),
			null,
			0,
			new DateTimeZone('America/New_York'),
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('* * * 4 *'),
			null,
			10,
		);
		$scheduler->addJob(
			new CallbackJob(Closure::fromCallable($cbs)),
			new CronExpression('* * * 3 *'),
			null,
			15,
			new DateTimeZone('America/New_York'),
		);

		$command = new ListCommand($scheduler, $clock);
		$tester = new CommandTester($command);

		putenv('COLUMNS=80');
		$tester->execute([
			'--explain' => null,
		]);

		self::assertSame(
			$explainDefault = <<<'MSG'
  * * * * *                            [0] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() Next Due: 59 seconds
    At every minute.
  */30 7-15 * * 1-5 (America/New_York) [1] Tests\Orisai\Scheduler\Doubles\CallbackList::job1() Next Due: 5 hours
    At every 30th minute past every hour from 7 through 15 on every day-of-week from Monday through Friday in America/New_York time zone.
  * * * 4 * / 10                       [2] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 2 months
    At every 10 seconds in April.
  * * * 3 * / 15 (America/New_York)    [3] Tests\Orisai\Scheduler\Doubles\CallbackList::__invoke() Next Due: 1 month
    At every 15 seconds in March in America/New_York time zone.

MSG,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());

		$tester->execute([
			'--explain' => 'en',
		]);

		self::assertSame(
			$explainDefault,
			CommandOutputHelper::getCommandOutput($tester),
		);
		self::assertSame($command::SUCCESS, $tester->getStatusCode());
	}

}

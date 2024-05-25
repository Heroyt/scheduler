<?php declare(strict_types = 1);

namespace Tests\Orisai\Scheduler\Unit\Job;

use Generator;
use Orisai\Exceptions\Logic\InvalidState;
use Orisai\Exceptions\Logic\NotImplemented;
use Orisai\Scheduler\Job\JobLock;
use Orisai\Scheduler\Job\SymfonyConsoleJob;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Lock\NoLock;
use Tests\Orisai\Scheduler\Doubles\TestExceptionCommand;
use Tests\Orisai\Scheduler\Doubles\TestFailNoOutputCommand;
use Tests\Orisai\Scheduler\Doubles\TestFailOutputCommand;
use Tests\Orisai\Scheduler\Doubles\TestLock;
use Tests\Orisai\Scheduler\Doubles\TestParametrizedCommand;
use Tests\Orisai\Scheduler\Doubles\TestSuccessCommand;
use Tests\Orisai\Scheduler\Helpers\CommandOutputHelper;
use Throwable;

final class SymfonyConsoleJobTest extends TestCase
{

	public function testSuccess(): void
	{
		$command = new TestSuccessCommand();
		$application = new Application();
		$application->add($command);
		$job = new SymfonyConsoleJob($command, $application);

		self::assertStringMatchesFormat('symfony/console: %ctest:success%c', $job->getName());

		// No output, no need to assert
		$job->run(new JobLock(new NoLock()));
	}

	public function testFailNoOutput(): void
	{
		$command = new TestFailNoOutputCommand();
		$application = new Application();
		$application->add($command);
		$job = new SymfonyConsoleJob($command, $application);

		self::assertStringMatchesFormat('symfony/console: %ctest:fail-no-output%c', $job->getName());

		$e = null;
		try {
			$job->run(new JobLock(new NoLock()));
		} catch (InvalidState $e) {
			// Handled bellow
		}

		self::assertNotNull($e);
		self::assertSame(1, $e->getCode());
		self::assertSame(
			<<<'MSG'
Context: Running command 'test:fail-no-output'.
Problem: Run failed with code '1'.
MSG,
			$e->getMessage(),
		);
		self::assertNull($e->getPrevious());
	}

	public function testFailOutput(): void
	{
		$command = new TestFailOutputCommand();
		$application = new Application();
		$application->add($command);
		$job = new SymfonyConsoleJob($command, $application);

		self::assertStringMatchesFormat('symfony/console: %ctest:fail-output%c', $job->getName());

		$e = null;
		try {
			$job->run(new JobLock(new NoLock()));
		} catch (InvalidState $e) {
			// Handled bellow
		}

		self::assertNotNull($e);
		self::assertSame(256, $e->getCode());
		self::assertSame(
			<<<'MSG'
Context: Running command 'test:fail-output'.
Problem: Run failed with code '256'.
Output: Failure!
        New line!

MSG,
			CommandOutputHelper::getCommandOutput($e->getMessage()),
		);
		self::assertNull($e->getPrevious());
	}

	/**
	 * @dataProvider provideApplicationSettingsHaveNoEffect
	 */
	public function testApplicationSettingsHaveNoEffect(bool $autoExit, bool $catchExceptions): void
	{
		$command = new TestExceptionCommand(1);
		$application = new Application();
		$application->setAutoExit($autoExit);
		$application->setCatchExceptions($catchExceptions);
		$application->add($command);
		$job = new SymfonyConsoleJob($command, $application);

		self::assertStringMatchesFormat('symfony/console: %ctest:exception%c', $job->getName());

		$e = null;
		try {
			$job->run(new JobLock(new NoLock()));
		} catch (Throwable $e) {
			// Handled bellow
		}

		self::assertInstanceOf(NotImplemented::class, $e);
		self::assertSame(1, $e->getCode());
		self::assertNull($e->getPrevious());
	}

	public function provideApplicationSettingsHaveNoEffect(): Generator
	{
		yield [true, true];
		yield [false, false];
		yield [true, false];
		yield [false, true];
	}

	public function testCommandNameCannotBeChanged(): void
	{
		$command = new TestSuccessCommand();
		$application = new Application();
		$application->add($command);
		$job = new SymfonyConsoleJob($command, $application);
		// Is ignored
		$job->setCommandParameters(['command' => 'non-existent']);

		// No output, no need to assert
		$job->run(new JobLock(new NoLock()));

		/** @phpstan-ignore-next-line */
		self::assertTrue(true);
	}

	public function testCommandParameters(): void
	{
		$command = new TestParametrizedCommand();
		$application = new Application();
		$application->add($command);
		$job = new SymfonyConsoleJob($command, $application);
		$job->setCommandParameters([
			'argument' => 'a',
			'--option' => 'b',
			'--bool-option' => true,
		]);

		self::assertStringMatchesFormat(
			'symfony/console: %ctest:parameters%c a --option=b --bool-option=1',
			$job->getName(),
		);

		// No output, no need to assert
		$job->run(new JobLock(new NoLock()));
	}

	public function testLockTtl(): void
	{
		$command = new TestSuccessCommand();
		$application = new Application();
		$application->add($command);
		$job = new SymfonyConsoleJob($command, $application);
		$job->setLockTtl(0.1);

		$lock = new TestLock();
		$job->run(new JobLock($lock));

		self::assertSame(
			[
				['refresh', 0.1],
			],
			$lock->calls,
		);
	}

}

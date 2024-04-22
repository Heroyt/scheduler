<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Command;

use DateTimeZone;
use Orisai\CronExpressionExplainer\CronExpressionExplainer;
use Orisai\CronExpressionExplainer\Exception\UnsupportedExpression;
use Orisai\Scheduler\Scheduler;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function array_key_exists;
use function assert;
use function in_array;
use function is_string;
use function preg_match;
use function timezone_identifiers_list;

final class ExplainCommand extends BaseExplainCommand
{

	private Scheduler $scheduler;

	public function __construct(
		Scheduler $scheduler,
		?CronExpressionExplainer $explainer = null,
		?ClockInterface $clock = null
	)
	{
		parent::__construct($explainer, $clock);
		$this->scheduler = $scheduler;
	}

	public static function getDefaultName(): string
	{
		return 'scheduler:explain';
	}

	public static function getDefaultDescription(): string
	{
		return 'Explain cron expression';
	}

	protected function configure(): void
	{
		/** @infection-ignore-all */
		parent::configure();
		$this->addOption('id', null, InputOption::VALUE_REQUIRED, 'ID of job to explain');
		$this->addOption('expression', 'e', InputOption::VALUE_REQUIRED, 'Expression to explain');
		$this->addOption('seconds', 's', InputOption::VALUE_REQUIRED, 'Repeat every n seconds');
		$this->addOption('timezone', 'tz', InputOption::VALUE_REQUIRED, 'The timezone time should be displayed in');
		$this->addOption(
			'locale',
			'l',
			InputOption::VALUE_REQUIRED,
			"Translate expression in given locale - {$this->getSupportedLocales()}",
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$options = $this->validateOptions($input, $output);
		if ($options === null) {
			return self::FAILURE;
		}

		$id = $options['id'];
		$expression = $options['expression'];
		$seconds = $options['seconds'];
		$timezone = $options['timezone'];
		$locale = $options['locale'];

		if ($id !== null) {
			return $this->explainJobWithId($id, $output);
		}

		if ($expression !== null) {
			return $this->explainExpression($expression, $seconds, $timezone, $locale, $output);
		}

		return $this->explainSyntax($output);
	}

	/**
	 * @return array{id: string|null, expression: string|null, seconds: int<0, 59>|null, timezone: DateTimeZone|null, locale: string|null}|null
	 */
	private function validateOptions(InputInterface $input, OutputInterface $output): ?array
	{
		$hasErrors = false;

		$id = $input->getOption('id');
		assert(is_string($id) || $id === null);

		$expression = $input->getOption('expression');
		assert(is_string($expression) || $expression === null);

		if ($id !== null && $expression !== null) {
			$hasErrors = true;
			$output->writeln('<error>Options --id and --expression cannot be combined.</error>');
		}

		$seconds = $input->getOption('seconds');
		assert(is_string($seconds) || $seconds === null);
		if ($seconds !== null) {
			if (
				/** @infection-ignore-all */
				preg_match('#^[+-]?[0-9]+$#D', $seconds) !== 1
				|| ($seconds = (int) $seconds) < 0
				|| $seconds > 59
			) {
				$hasErrors = true;
				$output->writeln("<error>Option --seconds expects an int<0, 59>, '$seconds' given.</error>");
			}

			if ($expression === null) {
				$hasErrors = true;
				$output->writeln('<error>Option --seconds must be used with --expression.</error>');
			}
		}

		$timezone = $input->getOption('timezone');
		assert(is_string($timezone) || $timezone === null);
		if ($timezone !== null) {
			if (!in_array($timezone, timezone_identifiers_list(), true)) {
				$hasErrors = true;
				$output->writeln("<error>Option --timezone expects a valid timezone, '$timezone' given.</error>");
			} else {
				$timezone = new DateTimeZone($timezone);
			}

			if ($expression === null) {
				$hasErrors = true;
				$output->writeln('<error>Option --timezone must be used with --expression.</error>');
			}
		}

		$locale = $input->getOption('locale');
		assert($locale === null || is_string($locale));
		if ($locale !== null) {
			if (!array_key_exists($locale, $this->explainer->getSupportedLocales())) {
				$hasErrors = true;
				$output->writeln(
					"<error>Option --locale expects no value or one of supported locales, '$locale' given."
					. ' Use --help to list available locales.</error>',
				);
			}

			if ($expression === null) {
				$hasErrors = true;
				$output->writeln('<error>Option --locale must be used with --expression.</error>');
			}
		}

		if ($hasErrors) {
			return null;
		}

		// Happens only when $hasErrors = true
		assert(!is_string($seconds) && $seconds >= 0 && $seconds <= 59);
		assert(!is_string($timezone));

		return [
			'id' => $id,
			'expression' => $expression,
			'seconds' => $seconds,
			'timezone' => $timezone,
			'locale' => $locale,
		];
	}

	/**
	 * @param int<0, 59>|null $seconds
	 */
	private function explainExpression(
		string $expression,
		?int $seconds,
		?DateTimeZone $timeZone,
		?string $locale,
		OutputInterface $output
	): int
	{
		try {
			$output->writeln($this->explainer->explain(
				$expression,
				$seconds,
				$timeZone,
				$locale,
			));
		} catch (UnsupportedExpression $exception) {
			$output->writeln("<error>{$exception->getMessage()}</error>");

			return self::FAILURE;
		}

		return self::SUCCESS;
	}

	private function explainJobWithId(string $id, OutputInterface $output): int
	{
		$jobSchedules = $this->scheduler->getJobSchedules();
		$jobSchedule = $jobSchedules[$id] ?? null;

		if ($jobSchedule === null) {
			$output->writeln("<error>Job with id '$id' does not exist.</error>");

			return self::FAILURE;
		}

		$output->writeln($this->explainer->explain(
			$jobSchedule->getExpression()->getExpression(),
			$jobSchedule->getRepeatAfterSeconds(),
			$this->computeTimeZone($jobSchedule, $this->clock->now()->getTimezone()),
		));

		return self::SUCCESS;
	}

	private function explainSyntax(OutputInterface $output): int
	{
		$output->writeln(
			<<<'CMD'
<fg=yellow>*   *   *   *   *</>
-   -   -   -   -
|   |   |   |   |
|   |   |   |   |
|   |   |   |   +----- day of week (<fg=yellow>0-7</>) (Sunday = <fg=yellow>0</> or <fg=yellow>7</>) (or <fg=yellow>SUN-SAT</>)
|   |   |   +--------- month (<fg=yellow>1-12</>) (or <fg=yellow>JAN-DEC</>)
|   |   +------------- day of month (<fg=yellow>1-31</>)
|   +----------------- hour (<fg=yellow>0-23</>)
+--------------------- minute (<fg=yellow>0-59</>)

Each part of expression can also use wildcard, lists, ranges and steps:

- wildcard - match always
  - e.g. <fg=yellow>* * * * *</> - At every minute.
  - day of week and day of month also support <fg=yellow>?</>, an alias to <fg=yellow>*</>
- lists - match list of values, ranges and steps
  - e.g. <fg=yellow>15,30 * * * *</> - At minute 15 and 30.
- ranges - match values in range
  - e.g. <fg=yellow>1-9 * * * *</> - At every minute from 1 through 9.
- steps - match every nth value in range
  - e.g. <fg=yellow>*/5 * * * *</> - At every 5th minute.
  - e.g. <fg=yellow>0-30/5 * * * *</> - At every 5th minute from 0 through 30.
- combinations
  - e.g. <fg=yellow>0-14,30-44 * * * *</> - At every minute from 0 through 14 and every minute from 30 through 44.

You can also use macro instead of an expression:

- <fg=yellow>@yearly</>, <fg=yellow>@annually</> - Run once a year, midnight, Jan. 1 (same as <fg=yellow>0 0 1 1 *</>)
- <fg=yellow>@monthly</> - Run once a month, midnight, first of month (same as <fg=yellow>0 0 1 * *</>)
- <fg=yellow>@weekly</> - Run once a week, midnight on Sun (same as <fg=yellow>0 0 * * 0</>)
- <fg=yellow>@daily</>, <fg=yellow>@midnight</> - Run once a day, midnight (same as <fg=yellow>0 0 * * *</>)
- <fg=yellow>@hourly</> - Run once an hour, first minute (same as <fg=yellow>0 * * * *</>)

Day of month extra features:

- nearest weekday - weekday (Monday-Friday) nearest to the given day
  - e.g. <fg=yellow>* * 15W * *</> - At every minute on a weekday nearest to the 15th.
  - If you were to specify <fg=yellow>15W</> as the value, the meaning is: "the nearest weekday to the 15th of the month"
    So if the 15th is a Saturday, the trigger will fire on Friday the 14th.
    If the 15th is a Sunday, the trigger will fire on Monday the 16th.
    If the 15th is a Tuesday, then it will fire on Tuesday the 15th.
  - However, if you specify <fg=yellow>1W</> as the value for day-of-month,
    and the 1st is a Saturday, the trigger will fire on Monday the 3rd,
    as it will not 'jump' over the boundary of a month's days.
- last day of the month
  - e.g. <fg=yellow>* * L * *</> - At every minute on a last day-of-month.
- last weekday of the month
  - e.g. <fg=yellow>* * LW * *</> - At every minute on a last weekday.

Day of week extra features:

- nth day
  - e.g. <fg=yellow>* * * * 7#4</> - At every minute on 4th Sunday.
  - 1-5
  - Every day of week repeats 4-5 times a month. To target the last one, use "last day" feature instead.
- last day
  - e.g. <fg=yellow>* * * * 7L</> - At every minute on the last Sunday.

Although they are not part of cron expression syntax, you can also add to job:

- seconds - repeat job every n seconds
- timezone - run only when cron expression matches within given timezone
CMD,
		);

		return self::SUCCESS;
	}

}

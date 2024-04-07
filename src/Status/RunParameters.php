<?php declare(strict_types = 1);

namespace Orisai\Scheduler\Status;

/**
 * @internal
 */
final class RunParameters
{

	/** @var int<0, max> */
	private int $second;

	private bool $forcedRun;

	/**
	 * @param int<0, max> $second
	 */
	public function __construct(int $second, bool $forcedRun)
	{
		$this->second = $second;
		$this->forcedRun = $forcedRun;
	}

	/**
	 * @param array<mixed> $raw
	 */
	public static function fromArray(array $raw): self
	{
		return new self($raw['second'], $raw['forcedRun']);
	}

	/**
	 * @return int<0, max>
	 */
	public function getSecond(): int
	{
		return $this->second;
	}

	public function isForcedRun(): bool
	{
		return $this->forcedRun;
	}

	/**
	 * @return array<mixed>
	 */
	public function toArray(): array
	{
		return [
			'second' => $this->second,
			'forcedRun' => $this->forcedRun,
		];
	}

}

<?php
declare(strict_types=1);

namespace Ramonak\Operation;

/** Who asks for an operation, as the frame's Klučnik vouches for them: a member, their level, the agent acting. */
final class Actor
{
    public const LEVELS = ['supracoŭnik' => 1, 'davierany' => 2, 'administratar' => 3];

    public function __construct(public readonly string $member, public readonly string $level, public readonly ?string $agent = null) {}

    public function rank(): int
    {
        return self::LEVELS[$this->level] ?? 0;
    }

    public function label(): string
    {
        return $this->member . ($this->agent ? '/' . $this->agent : '');
    }
}

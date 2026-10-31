<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Tests\Fixtures;

class User
{
    public int $id = 42;
    public string $name = 'Ada <Lovelace>';
    protected array $roles = ['admin', 'dev'];
    private ?Address $address;
    public ?User $self = null;

    public function __construct()
    {
        $this->address = new Address();
        $this->self = $this;
    }
}

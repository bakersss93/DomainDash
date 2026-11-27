<?php

namespace Tests\Support;

/** Scripted SoapClient double: queue responses (arrays/objects) or Throwables. */
class FakeSoap
{
    public array $calls = [];

    public function __construct(public array $queue = [])
    {
    }

    public function __soapCall(string $operation, array $args)
    {
        $this->calls[] = ['op' => $operation, 'args' => $args];
        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}

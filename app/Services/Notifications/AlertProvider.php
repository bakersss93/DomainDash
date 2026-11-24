<?php

namespace App\Services\Notifications;

interface AlertProvider
{
    /**
     * Alerts whose condition is currently true.
     *
     * @return list<array{key:string,severity:string,title:string,text:string,url:?string,at:?string}>
     */
    public function alerts(): array;
}

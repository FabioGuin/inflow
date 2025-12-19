<?php

namespace InFlow\Contracts;

interface SourceInterface
{
    /**
     * Returns a readable stream of the source content
     */
    public function stream();
}

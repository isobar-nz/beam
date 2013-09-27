<?php

namespace Heyday\Component\Beam\VcsProvider;

/**
 * Class VcsInformationProvider
 * @package Heyday\Component\Beam\VcsProvider
 */
interface VcsInformationProvider {

    /**
     * @param $ref
     * @return array|string
     */
    public function getBranchesContainingRef($ref);

    /**
     * Get the number of commits between a two refs
     * @param $ref
     * @param $otherRef
     * @return int
     */
    public function getDistanceBetweenRefs($ref, $otherRef);

}
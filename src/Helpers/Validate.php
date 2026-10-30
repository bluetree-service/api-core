<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Helpers;

use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates an input object built from a request.
 *
 * Modules describe what they expect with a class in their own Input namespace,
 * using Symfony constraint attributes, and hand it here. Controllers never
 * inspect request values themselves.
 */
class Validate
{
    protected ValidatorInterface $validator;

    public function __construct()
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    /**
     * @return string[] violation messages, empty when the input is valid
     */
    public function check(object $input): array
    {
        $messages = [];

        foreach ($this->validator->validate($input) as $violation) {
            $messages[] = (string)$violation->getMessage();
        }

        return $messages;
    }
}

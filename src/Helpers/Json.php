<?php

declare(strict_types=1);

namespace Bluetree\Api\Core\Helpers;

use Symfony\Component\HttpFoundation\Request;

class Json
{
    public static function readFile(string $path): array
    {
        return \json_decode(
            \file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param Request $request
     * @return array
     * @throws InvalidJsonException
     */
    public static function jsonRequest(Request $request): array
    {
        $data = $request->getContent();

        if (empty($data)) {
            return [];
        }

        $decoded = \json_decode($data, true);

        if (!\is_array($decoded)) {
            throw new InvalidJsonException('The request body is not a JSON object or array');
        }

        return $decoded;
    }

    /**
     * Request payload whether it arrived as JSON or as form fields.
     *
     * The Vue front end posts JSON; the legacy b2b_calc front end posts a form.
     * Both have to keep working until the old one is retired.
     *
     * @throws \JsonException
     */
    public static function payload(Request $request): array
    {
        $form = $request->request->all();

        return $form !== [] ? $form : self::jsonRequest($request);
    }
}

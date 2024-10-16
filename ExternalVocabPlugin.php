<?php

/**
 * @file plugins/generic/externalVocab/ExternalVocabPlugin.php
 *
 * Copyright (c) 2024 Mä ja sä
 * Distributed under the GNU GPL v3. For full terms see LICENSE or https://www.gnu.org/licenses/gpl-3.0.txt
 *
 * @class ExternalVocab
 * @ingroup plugins_generic_externalVocab
 *
 */

namespace APP\plugins\generic\externalVocab;

use GuzzleHttp\Client;
use GuzzleHttp\Promise;

use APP\core\Application;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\submission\SubmissionAgencyDAO;
use PKP\submission\SubmissionDisciplineDAO;
use PKP\submission\SubmissionKeywordDAO;
use PKP\submission\SubmissionSubjectDAO;

class ExternalVocabPlugin extends GenericPlugin
{

    /**
     * Constants
     */

    // Define which vocabularies are supported, and the languages in them
    const ALLOWED_VOCABS_AND_LANGS = [
        SubmissionAgencyDAO::CONTROLLED_VOCAB_SUBMISSION_AGENCY => [],
        SubmissionDisciplineDAO::CONTROLLED_VOCAB_SUBMISSION_DISCIPLINE => ['fi', 'sv', 'en'],
        SubmissionKeywordDAO::CONTROLLED_VOCAB_SUBMISSION_KEYWORD => ['fi', 'sv', 'en'],
        SubmissionSubjectDAO::CONTROLLED_VOCAB_SUBMISSION_SUBJECT => ['fi', 'sv', 'en'],
    ];

    /**
     * Public 
     */

    // GenericPlugin methods

    public function register($category, $path, $mainContextId = null)
    {
        if (Application::isUnderMaintenance()) {
            return true;
        }
        $success = parent::register($category, $path);
        if ($success && $this->getEnabled()) {
            // Add hook
            Hook::add('API::vocabs::external', $this->setData(...));
        }
        return $success;
    }

    public function getActions($request, $actionArgs)
    {
        return parent::getActions($request, $actionArgs);
    }

    public function getDisplayName()
    {
        return __('plugins.generic.externalVocab.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.externalVocab.description');
    }

    public function getCanEnable()
    {
        return !!Application::get()->getRequest()->getContext();
    }

    public function getCanDisable()
    {
        return !!Application::get()->getRequest()->getContext();
    }

    public function manage($args, $request)
    {
        return parent::manage($args, $request);
    }

    // Own methods

    /**
     * Public
     */

    public function setData(string $hookName, array $args): bool
    {
        $vocab = $args[0];
        $term = $args[1];
        $locale = $args[2];
        $data = &$args[3];
        $entries = &$args[4];
        $illuminateRequest = $args[5];
        $response = $args[6];
        $request = $args[7];

        // Here we define which form field the plugin is triggered in.
        // You can also define the language is the specific field while some vocabularies might only work
        // with specific languages.
        // Note that the current development version of the core only supports extending Keywords.
        // However, this will be extended to other fields as well, like Discipline.
        if (!isset(self::ALLOWED_VOCABS_AND_LANGS[$vocab]) || !in_array($locale, self::ALLOWED_VOCABS_AND_LANGS[$vocab])) {
            return false;
        }

        // We call the fetchData function will handle the interaction with the vocabulary
        $resultData = $this->fetchData($term, $locale, $vocab);

        // We replace the vocabulary data coming from the OJS database with fetched data
        // from the external vocabulary and only show those results as suggestions.
        // If you want to show also suggestions from existing keywords in your own database
        // this is where we can make that decision.

        if (!$resultData) {
            $data = [];
            return false;
        }

        $data = $resultData;

        return false;
    }

    /**
     * Private
     */

    /**
     * @return [ [ 'term' => string, 'label' => ?string, 'identifier' =>  ?string, ... ], ... ]
     */
    private function fetchData(?string $term, string $locale, string $vocab): array
    {
        // You might want to consider sanitazing the search term before it is 
        // passed to an API or used to search within a local file
        $termSanitized = $this->sanitizeTerm($term);

        // Here we can set the minimum length for the word that is used for the query
        if (strlen($termSanitized) < 3) {
            return [];
        }

        // The following example connects to an external vocabulary called Finto
        // using an open REST API and Guzzle HTTP Client.
        // More than one is supported, and the results are grouped by the vocabulary service.
        // This is the part of the code that can vary depending on the vocabulary used.
        // You could rewrite the code to support another REST API based vocabulary or to 
        // interact with a local file for example in the plugin folder. This might work
        // for smaller vocabularies and could be also used easily to define a strict set
        // of keywords locally.

        return match ($vocab) {
            SubmissionAgencyDAO::CONTROLLED_VOCAB_SUBMISSION_AGENCY => $this->agency($termSanitized, $locale),
            SubmissionDisciplineDAO::CONTROLLED_VOCAB_SUBMISSION_DISCIPLINE => $this->discipline($termSanitized, $locale),
            SubmissionKeywordDAO::CONTROLLED_VOCAB_SUBMISSION_KEYWORD,
                SubmissionSubjectDAO::CONTROLLED_VOCAB_SUBMISSION_SUBJECT => $this->keyword($termSanitized, $locale),
        };
    }

    /**
     * Functions for vocabularies
     */

    private function agency(string $termSanitized, string $locale): array
    {
        return [];
    }

    private function discipline(string $termSanitized, string $locale): array
    {
        // Finto supports fi, sv, en
        $finto = [
            'callback' => 'finto',
            'requestParams' => [
                'GET',
                "https://api.finto.fi/rest/v1/search",
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-type' => 'application/json'
                    ],
                    'timeout'  => 3.13,
                    'query' => [
                        'vocab' => 'koko',
                        'query' => "$termSanitized*",
                        'lang' => $locale,
                        'type' => 'skos:Concept',
                        'parent' => 'http://www.yso.fi/onto/koko/p30642',
                        'maxhits' => 10,
                        'unique' => true,
                    ],
                ],
            ],
        ];

        $requests = [
            'fintoKoko' => $finto,
        ];

        return $this->getResponses($requests);
    }

    private function keyword(string $termSanitized, string $locale): array
    {
        // Finto supports fi, sv, en
        $finto = [
            'callback' => 'finto',
            'requestParams' => [
                'GET',
                "https://api.finto.fi/rest/v1/search",
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-type' => 'application/json'
                    ],
                    'timeout'  => 3.13,
                    'query' => [
                        'vocab' => 'koko',
                        'query' => "$termSanitized*",
                        'lang' => $locale,
                        'maxhits' => 10,
                    ],
                ],
            ],
        ];

        $requests = [
            'fintoKoko' => $finto,
        ];

        return $this->getResponses($requests);
    }

    private function subject(string $termSanitized, string $locale): array
    {
        return [];
    }

    /**
     * Functions for responses
     */

    private function getResponses(array $requests): array
    {
        $requests = collect($requests);
        $client = new Client();
        $responses = Promise\Utils::settle($requests
                ->map(fn ($req) => $client->requestAsync(...$req['requestParams']))
                ->toArray())
            ->wait();

        return $requests
            ->map(function (array $request, string $key) use ($responses): array {
                $response = $responses[$key]['value'] ?? null;
                if ($response?->getStatusCode() === 200) {
                    // $this->{$request['callback']}: e.g. 'finto' ( $this->finto(...) ).
                    // You might want to consider sanitazing the suggestions before they are returned from the function.
                    return $this->{$request['callback']}(json_decode($response->getBody()->getContents(), true));
                }
                return [];
            })
            ->flatten(1)
            ->toArray();
    }

    private function finto(?array $responseContents): array
    {
        return collect($responseContents['results'] ?? [])
            ->unique('uri')
            ->filter()
            ->map(function (array $d): array {
                $term = $this->sanitizeTerm($d['prefLabel']);
                $uri = $this->regexMatch("#^http://www\.yso\.fi/#", $this->sanitizeTerm($d['uri']));
                return [
                    'term' => $term /* Required */,
                    'label' => $term . ($uri ? " [ $uri ]" : "") /* This is the optional custom label that will be stored separately */,
                    'identifier' => $uri /* This is the optional unique identifier, e.g. uri, that will be stored separately */,
                    /* Optional extra items here */
                    'service' => 'finto',
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Aux functions
     */

    private function regexMatch(string $pattern, string $subject, string $default = null): ?string
    {
        return preg_match($pattern, $subject) ? $subject : $default;
    }

    private function sanitizeTerm(?string $term): string
    {
        $term ??= "";
        return preg_replace("/\n\r\t\v\x00/", "", trim(strip_tags($term))) ?? "";
    }
}

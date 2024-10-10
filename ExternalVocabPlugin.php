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
use PKP\submission\SubmissionKeywordDAO;

class ExternalVocabPlugin extends GenericPlugin
{

    /**
     * Constants
     */

    // Define which vocabularies are supported, and the languages in them
    const ALLOWED_VOCABS_AND_LANGS = [
        SubmissionKeywordDAO::CONTROLLED_VOCAB_SUBMISSION_KEYWORD => ['fi', 'sv', 'en'],
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
        $resultData = $this->fetchData($term, $locale);

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

    private function fetchData(?string $term, string $locale): array
    {
        // You might want to consider sanitazing the search term before it is 
        // passed to an API or used to search within a local file
        $termSanitized = $term ?? "";

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

        $client = new Client();
        $promises = [
            'finto' => $client->requestAsync(
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
                ]
            ),
        ];
        $responses = Promise\Utils::settle($promises)->wait();

        return collect($responses)
            ->reduce(function (array $data, array $response, string $service): array {
                if (isset($response['value']) && $response['value']->getStatusCode() === 200) {
                    // $this->{$service}: '$service' is the '$promises' array key, e.g. 'finto' ( $this->finto(...) )
                    // You might want to consider sanitazing the suggestions before they are returned from the function '$this->{$service}'
                    array_push($data, ...$this->{$service}(json_decode($response['value']->getBody()->getContents(), true)));
                }
                return $data;
            }, []);
    }

    /**
     * Functions for vocabularies
     */

    private function finto(?array $responseContents)
    {
        return collect($responseContents['results'] ?? [])
            ->unique('uri')
            ->filter()
            ->map(fn (array $d): array =>
                [
                    'term' => $d['prefLabel'],
                    'label' => "{$d['prefLabel']} [ {$d['uri']} ]" /** This is the optional custom label that will be stored separately */,
                    'uri' => $d['uri'] /** This is the optional unique identifier that will be stored separately */,
                    /* Extra items here */
                    'service' => 'finto',
                ])
            ->values()
            ->toArray();
    }
}

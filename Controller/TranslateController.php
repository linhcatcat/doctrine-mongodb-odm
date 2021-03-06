<?php

/*
 * Copyright 2011 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\TranslationBundle\Controller;

use JMS\TranslationBundle\Exception\RuntimeException;
use JMS\TranslationBundle\Exception\InvalidArgumentException;
use JMS\TranslationBundle\Util\FileUtils;
use JMS\DiExtraBundle\Annotation as DI;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Translate Controller.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class TranslateController
{
    private $configFactory;

    private $loader;

    private $container;

    private $sourceLanguage;

	public function __construct($configFactory, $loader, $container, $templating, $sourceLanguage)
	{
		$this->configFactory  = $configFactory;
		$this->loader         = $loader;
		$this->container      = $container;
		$this->sourceLanguage = $sourceLanguage;
		$this->templating     = $templating;
	}

    /**
     * @Route("/", name="jms_translation_index", options = {"i18n" = false})
     * @Template
     * @param string $config
     */
    public function indexAction(Request $request)
    {
        $configs = $this->configFactory->getNames();
        $config = $request->query->get('config') ?: reset($configs);
        if (!$config) {
            throw new RuntimeException('You need to configure at least one config under "jms_translation.configs".');
        }

        $translationsDir = $this->configFactory->getConfig($config, 'en')->getTranslationsDir();
        $files = FileUtils::findTranslationFiles($translationsDir);
        if (empty($files)) {
            throw new RuntimeException('There are no translation files for this config, please run the translation:extract command first.');
        }

        $domains = array_keys($files);
        $domain = $request->query->get('domain') ?: reset($domains);
        if ((!$domain = $request->query->get('domain')) || !isset($files[$domain])) {
            $domain = reset($domains);
        }

        $locales = array_keys($files[$domain]);
        if ((!$locale = $request->query->get('locale')) || !isset($files[$domain][$locale])) {
            $locale = reset($locales);
        }

        $catalogue = $this->loader->loadFile(
            $files[$domain][$locale][1]->getPathName(),
            $files[$domain][$locale][0],
            $locale,
            $domain
        );

        // create alternative messages
        // TODO: We should probably also add these to the XLIFF file for external translators,
        //       and the specification already supports it
        $alternativeMessages = array();
        foreach ($locales as $otherLocale) {
            if ($locale === $otherLocale) {
                continue;
            }

            $altCatalogue = $this->loader->loadFile(
                $files[$domain][$otherLocale][1]->getPathName(),
                $files[$domain][$otherLocale][0],
                $otherLocale,
                $domain
            );
            foreach ($altCatalogue->getDomain($domain)->all() as $id => $message) {
                $alternativeMessages[$id][$otherLocale] = $message;
            }
        }

        $newMessages = $existingMessages = array();
        foreach ($catalogue->getDomain($domain)->all() as $id => $message) {
            if ($message->isNew()) {
                $newMessages[$id] = $message;
                continue;
            }

            $existingMessages[$id] = $message;
        }

        return new Response($this->templating->render('JMSTranslationBundle:Translate:index.html.twig', array(
            'selectedConfig' => $config,
            'configs' => $configs,
            'selectedDomain' => $domain,
            'domains' => $domains,
            'selectedLocale' => $locale,
            'locales' => $locales,
            'format' => $files[$domain][$locale][0],
            'newMessages' => $newMessages,
            'existingMessages' => $existingMessages,
            'alternativeMessages' => $alternativeMessages,
            'isWriteable' => is_writeable($files[$domain][$locale][1]),
            'file' => (string) $files[$domain][$locale][1],
            'sourceLanguage' => $this->sourceLanguage,
        )));
    }
}
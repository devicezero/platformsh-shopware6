<?php


namespace Shopware\CI\Service;


use League\Flysystem\Filesystem;
use Shopware\CI\Service\Xml\Release;

class ReleasePrepareService
{
    public const SHOPWARE_XML_PATH = '_meta/shopware6.xml';

    /**
     * @var array
     */
    private $config;

    /**
     * @var Filesystem
     */
    private $deployFilesystem;

    /**
     * @var ChangelogService
     */
    private $changelogService;

    /**
     * @var Filesystem
     */
    private $artifactsFilesystem;

    /**
     * @var UpdateApiService
     */
    private $updateApiService;

    public function __construct(
        array $config,
        Filesystem $deployFilesystem,
        FileSystem $artifactsFilesystem,
        ChangelogService $changelogService,
        UpdateApiService $updateApiService
    )
    {
        $this->config = $config;
        $this->deployFilesystem = $deployFilesystem;
        $this->changelogService = $changelogService;
        $this->artifactsFilesystem = $artifactsFilesystem;
        $this->updateApiService = $updateApiService;
    }

    public function prepareRelease(string $tag): void
    {
        $releaseList = $this->getReleaseList();

        $release = $releaseList->getRelease($tag);
        if ($release === null) {
            $release = $releaseList->addRelease($tag);
        }

        if ($release->isPublic()) {
            throw new \RuntimeException('Release ' . $tag . ' is already public');
        }

        $this->setReleaseProperties($tag, $release);

        $this->uploadArchives($release);

        if($this->mayAlterChangelog($release)) {
            try {
                $changelog = $this->changelogService->getChangeLog($tag);
                $release->setLocales($changelog);
            } catch (\Throwable $e) {
                var_dump($e);
            }
        } else {
            echo 'May not alter changelog ' . PHP_EOL;
        }

        $this->storeReleaseList($releaseList);

        $this->registerUpdate($tag, $release);
    }

    public function uploadArchives(Release $release): void
    {
        $installUpload = $this->hashAndUpload($release->tag, 'install.zip');
        $release->download_link_install = $installUpload['url'];
        $release->sha1_install = $installUpload['sha1'];
        $release->sha256_install = $installUpload['sha256'];

        $updateUpload = $this->hashAndUpload($release->tag, 'update.zip');
        $release->download_link_update = $updateUpload['url'];
        $release->sha1_update = $updateUpload['sha1'];
        $release->sha256_update = $updateUpload['sha256'];

        $this->hashAndUpload($release->tag, 'install.tar.xz');
        $minorBranch = VersioningService::getMinorBranch($release->tag);
        $this->hashAndUpload(
            $release->tag,
            'install.tar.xz',
            'sw6/install_' . $minorBranch . '_next.tar.xz' // 6.2_next.tar.xz, 6.3.0_next.tar.xz, 6.3.1_next.tar.xz
        );
    }

    public function getReleaseList(): Release
    {
        $content = $this->deployFilesystem->read(self::SHOPWARE_XML_PATH);
        return simplexml_load_string($content, Release::class);
    }

    public function storeReleaseList(Release $release): void
    {
        $dom = new \DOMDocument("1.0");
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($release->asXML());

        $this->deployFilesystem->put(self::SHOPWARE_XML_PATH, $dom->saveXML());
    }

    public function registerUpdate(string $tag, Release $release): void
    {
        $baseParams = [
            '--release-version' => (string)$release->version,
            '--channel' => VersioningService::getUpdateChannel($tag),
        ];

        if (((string)$release->version_text) !== '') {
            $baseParams['--version-text'] = (string)$release->version_text;
        }

        $insertReleaseParameters = array_merge($baseParams, [
            '--min-version' => $this->config['minimumVersion'] ?? '6.2.0',
            '--install-uri' => (string)$release->download_link_install,
            '--install-size' => (string)$this->artifactsFilesystem->getSize('install.zip'),
            '--install-sha1' => (string)$release->sha1_install,
            '--install-sha256' => (string)$release->sha256_install,
            '--update-uri' => (string)$release->download_link_update,
            '--update-size' => (string)$this->artifactsFilesystem->getSize('update.zip'),
            '--update-sha1' => (string)$release->sha1_update,
            '--update-sha256' => (string)$release->sha256_update
        ]);

        $this->updateApiService->insertReleaseData($insertReleaseParameters);
        $this->updateApiService->updateReleaseNotes($baseParams);

        if ($release->isPublic()) {
            $this->updateApiService->publishRelease($baseParams);
        }
    }

    private function setReleaseProperties(string $tag, Release $release): void
    {
        $release->minimum_version = $this->config['minimumVersion'] ?? '6.2.0';
        $release->public = 0;
        $release->ea = 0;
        $release->revision = '';
        $release->type = VersioningService::getReleaseType($tag);
        $release->release_date = '';
        $release->tag = $tag;
        $release->github_repo = 'https://github.com/shopware/platform/tree/' . $tag;

        $release->upgrade_md = sprintf(
            'https://github.com/shopware/platform/blob/%s/UPGRADE-%s.md',
            $tag,
            VersioningService::getMajorBranch($tag)
        );
    }

    private function hashAndUpload(string $tag, string $source, string $targetPath = null): array
    {
        $sha1 = $this->hashFile('sha1', $source);
        $sha256 = $this->hashFile('sha256', $source);

        $basename = basename($source);
        $parts = explode('.', $basename, 2);

        $targetPath = $targetPath ?: 'sw6/' . $parts[0] . '_' . $tag . '_' . $sha1 . '.' . $parts[1];
        $this->deployFilesystem->putStream($targetPath, $this->artifactsFilesystem->readStream($source));
        return [
            'url' => $this->config['deployFilesystem']['publicDomain'] . '/' . $targetPath,
            'sha1' => $sha1,
            'sha256' => $sha256
        ];
    }

    private function hashFile(string $alg, string $path): string
    {
        $context = hash_init($alg);
        hash_update_stream($context, $this->artifactsFilesystem->readStream($path));
        return hash_final($context);
    }

    private function mayAlterChangelog(Release $release): bool
    {
        return !$release->isPublic()
            && ((bool)($release->manual ?? false)) !== true;
    }
}

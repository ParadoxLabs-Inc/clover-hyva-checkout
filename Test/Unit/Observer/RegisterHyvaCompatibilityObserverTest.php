<?php

declare(strict_types=1);

namespace ParadoxLabs\CloverHyvaCheckout\Test\Unit\Observer;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use ParadoxLabs\CloverHyvaCheckout\Observer\RegisterHyvaCompatibilityObserver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Hyva Tailwind purge registration observer
 */
class RegisterHyvaCompatibilityObserverTest extends TestCase
{
    private RegisterHyvaCompatibilityObserver $observer;
    private ComponentRegistrar|MockObject $componentRegistrar;

    protected function setUp(): void
    {
        $this->componentRegistrar = $this->createMock(ComponentRegistrar::class);
        $this->observer = new RegisterHyvaCompatibilityObserver($this->componentRegistrar);
    }

    public function testRegistersPathRelativeToBaseDirForInTreeModule(): void
    {
        // Any real directory under the Magento root stands in for an in-tree module path.
        $this->componentRegistrar->method('getPath')
            ->with(ComponentRegistrar::MODULE, 'ParadoxLabs_CloverHyvaCheckout')
            ->willReturn(BP . '/vendor');

        $config = new DataObject();

        $this->observer->execute(new Observer(['config' => $config]));

        $this->assertSame([['src' => 'vendor']], $config->getData('extensions'));
    }

    public function testEmitsAppCodeSymlinkPathForSymlinkedModule(): void
    {
        // The module's own directory: in a symlinked dev setup its realpath is outside BP.
        $modulePath = (string)realpath(dirname(__DIR__, 3));

        if (str_starts_with($modulePath, BP . DIRECTORY_SEPARATOR)) {
            $this->markTestSkipped('Module directory is inside the Magento root; symlink case not reproducible.');
        }

        $this->componentRegistrar->method('getPath')->willReturn($modulePath);

        $config = new DataObject();

        $this->observer->execute(new Observer(['config' => $config]));

        $appCodePath = 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR
            . 'ParadoxLabs' . DIRECTORY_SEPARATOR . 'CloverHyvaCheckout';
        $symlink     = BP . DIRECTORY_SEPARATOR . $appCodePath;

        if (!is_link($symlink) || realpath($symlink) !== $modulePath) {
            $this->markTestSkipped('Module is not symlinked into app/code; symlink case not reproducible.');
        }

        // The old implementation registered a garbage substr(BP) slice here; the fix must emit
        // the app/code symlink location instead.
        $this->assertSame([['src' => $appCodePath]], $config->getData('extensions'));
    }

    public function testAppendsToExistingExtensionsList(): void
    {
        $this->componentRegistrar->method('getPath')->willReturn(BP . '/vendor');

        $config = new DataObject(['extensions' => [['src' => 'app/code/Other/Module']]]);

        $this->observer->execute(new Observer(['config' => $config]));

        $this->assertSame(
            [
                ['src' => 'app/code/Other/Module'],
                ['src' => 'vendor'],
            ],
            $config->getData('extensions')
        );
    }
}

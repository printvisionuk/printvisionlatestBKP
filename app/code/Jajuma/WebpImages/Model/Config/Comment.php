<?php declare(strict_types = 1);
/**
 * @author    JaJuMa GmbH <info@jajuma.de>
 * @copyright Copyright (c) 2020 JaJuMa GmbH <https://www.jajuma.de>. All rights reserved.
 * @license   http://opensource.org/licenses/mit-license.php MIT License
 */
namespace Jajuma\WebpImages\Model\Config;

class Comment implements \Magento\Config\Model\Config\CommentInterface
{
    /**
     * @var $dir
     */
    protected $dir;

    /**
     * Constructor
     *
     * @param \Magento\Framework\Filesystem\DirectoryList $dir
     */
    public function __construct(
        \Magento\Framework\Filesystem\DirectoryList $dir
    ) {
        $this->dir = $dir;
    }

    /**
     * Get comment text
     *
     * @param string $elementValue
     *
     * @return string
     */
    public function getCommentText($elementValue)
    {
        return 'Define the specify path of cwebp command or leave it empty to use global command "cwebp". Example: '
        . $this->dir->getPath('app') . '/code/Jajuma/WebpImages/bin/cwebp';
    }
}

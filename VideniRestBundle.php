<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Videni\Bundle\RestBundle;

use Videni\Bundle\RestBundle\DependencyInjection\Compiler\AnnotationFilterPass;
use Videni\Bundle\RestBundle\DependencyInjection\Compiler\DataProviderPass;
use Videni\Bundle\RestBundle\DependencyInjection\Compiler\FilterPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * The Symfony bundle.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class VideniRestBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $container->addCompilerPass(new DataProviderPass());
        $container->addCompilerPass(new AnnotationFilterPass());
        $container->addCompilerPass(new FilterPass());
    }
}

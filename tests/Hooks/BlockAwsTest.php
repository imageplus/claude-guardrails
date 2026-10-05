<?php

declare(strict_types=1);

namespace ImagePlus\ClaudeGuardrails\Tests\Hooks;

use PHPUnit\Framework\Attributes\DataProvider;

final class BlockAwsTest extends HookTestCase
{
    protected function hook(): string
    {
        return 'block-aws.php';
    }

    /** @return array<string,array{string}> */
    public static function blocked(): array
    {
        return [
            'bare'              => ['aws s3 ls'],
            'absolute path'     => ['/usr/local/bin/aws sts get-caller-identity'],
            'v2 binary'         => ['aws2 s3 ls'],
            'windows'           => ['aws.exe s3 ls'],
            'env assignment'    => ['AWS_PROFILE=prod aws s3 ls'],
            'env wrapper'       => ['env AWS_PROFILE=prod aws s3 ls'],
            'sudo'              => ['sudo aws ec2 describe-instances'],
            'timeout'           => ['timeout 30 aws s3 sync . s3://bucket'],
            'xargs'             => ['cat buckets.txt | xargs -n1 aws s3 ls'],
            'aws-vault'         => ['aws-vault exec prod -- aws s3 ls'],
            'chained'           => ['composer install && aws s3 cp x s3://y'],
            'after pipe'        => ['echo {} | aws lambda invoke --payload file:///dev/stdin out'],
            'single quotes'     => ["a''ws s3 ls"],
            'backslash'         => ['a\\ws s3 ls'],
            'brace'             => ['{aws,true} s3 ls'],
            'sh -c'             => ["sh -c 'aws s3 ls'"],
            'bash -c chained'   => ["bash -c 'cd /tmp && aws s3 ls'"],
            'eval'              => ["eval 'aws s3 ls'"],
            'python module'     => ['python3 -m awscli s3 ls'],
            'docker image'      => ['docker run --rm amazon/aws-cli s3 ls'],
            'docker ecr image'  => ['docker run public.ecr.aws/amazon/aws-cli:2.15.0 s3 ls'],
        ];
    }

    #[DataProvider('blocked')]
    public function testBlocks(string $command): void
    {
        self::assertSame(self::BLOCK, $this->bash($command));
    }

    public function testBlocksGlobThatResolvesToTheAwsBinary(): void
    {
        $this->touch('bin/aws');

        self::assertSame(self::BLOCK, $this->bash('./bin/aw? s3 ls'));
    }

    /** @return array<string,array{string}> */
    public static function allowed(): array
    {
        return [
            'sdk install'        => ['composer require aws/aws-sdk-php'],
            'grep for aws'       => ['grep -rn aws config/filesystems.php'],
            'aws directory'      => ['cd aws-infra && ls'],
            'aws as an argument' => ['echo aws'],
            'git log'            => ['git log --grep aws'],
            'artisan'            => ['php artisan queue:work sqs'],
            'pip install'        => ['pip install awscli'],
            'similar binary'     => ['awslocal s3 ls'],
            'vapor-ish name'     => ['npm run aws:build'],
        ];
    }

    #[DataProvider('allowed')]
    public function testAllows(string $command): void
    {
        self::assertSame(self::ALLOW, $this->bash($command));
    }

    public function testIgnoresNonBashTools(): void
    {
        self::assertSame(self::ALLOW, $this->file('Read', 'aws.php'));
    }

    public function testExplainsWhyOnStderr(): void
    {
        [, $stderr] = $this->runHook(['tool_name' => 'Bash', 'tool_input' => ['command' => 'aws s3 ls']]);

        self::assertStringContainsString('AWS CLI commands must be run manually', $stderr);
    }
}

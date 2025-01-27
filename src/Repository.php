<?php

declare(strict_types=1);

/*
 * This file is part of the ausi/remote-git package.
 *
 * (c) Martin Auswöger <martin@auswoeger.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ausi\RemoteGit;

use Ausi\RemoteGit\GitObject\Commit;
use Ausi\RemoteGit\GitObject\File;
use Ausi\RemoteGit\GitObject\GitObject;
use Ausi\RemoteGit\GitObject\GitObjectInterface;
use Ausi\RemoteGit\GitObject\Tree;
use Symfony\Component\Filesystem\Filesystem;

class Repository
{
	private GitExecutable $executable;
	private string $gitDir;
	private ?string $headBranchName = null;

	/**
	 * @var array<Branch>
	 */
	private array $branches = [];

	/**
	 * @param string                    $url               Remote GIT URL, e.g. ssh://user@example.com/repo.git
	 * @param string|null               $tempDirectory     Directory to store the shallow clone, defaults to sys_get_temp_dir()
	 * @param string|GitExecutable|null $gitExecutablePath Path to the git binary, defaults to the path found by the ExecutableFinder
	 */
	public function __construct(string $url, string $tempDirectory = null, string|GitExecutable $gitExecutablePath = null)
	{
		if (!$gitExecutablePath instanceof GitExecutable) {
			$gitExecutablePath = new GitExecutable($gitExecutablePath);
		}

		$this->executable = $gitExecutablePath;
		$this->gitDir = $this->createTempPath($tempDirectory);

		$this->initialize($url);
	}

	public function __destruct()
	{
		(new Filesystem)->remove($this->gitDir);
	}

	/**
	 * @return array<Branch>
	 */
	public function listBranches(): array
	{
		if ($this->branches) {
			return $this->branches;
		}

		$lines = explode("\n", trim($this->run('show-ref')));

		foreach ($lines as $line) {
			$cols = explode(' ', $line);

			if (strncmp($cols[1] ?? '', 'refs/remotes/origin/', 20) !== 0) {
				continue;
			}
			$this->branches[trim($cols[1])] = new Branch($this, substr(trim($cols[1]), 20), trim($cols[0]));
		}

		if (!$this->branches) {
			throw new \RuntimeException('Unable to list branches');
		}

		return array_values($this->branches);
	}

	public function getBranch(string $name): Branch
	{
		$this->listBranches();

		if ($name === 'HEAD') {
			$name = $this->getHeadBranchName();
		}

		if (!isset($this->branches['refs/remotes/origin/'.$name])) {
			throw new \RuntimeException('Unable to find branch');
		}

		return $this->branches['refs/remotes/origin/'.$name];
	}

	public function getHeadBranchName(): string
	{
		if ($this->headBranchName !== null) {
			return $this->headBranchName;
		}

		$this->run('remote set-head origin -a');
		$ref = trim($this->run('symbolic-ref refs/remotes/origin/HEAD'));

		if (strncmp($ref, 'refs/remotes/origin/', 20) !== 0) {
			throw new \RuntimeException('Unable to get HEAD branch');
		}

		return substr($ref, 20);
	}

	public function getCommit(string $commitHash): Commit
	{
		return new Commit($this, $commitHash);
	}

	public function getTreeFromCommit(string $commitHash): Tree
	{
		return new Tree(
			$this,
			trim($this->run('rev-parse', $commitHash.'^{tree}')),
		);
	}

	public function commitTree(Tree $tree, string $message, Commit ...$parents): Commit
	{
		$args = [$tree->getHash(), '-m', $message];

		foreach ($parents as $parent) {
			$args[] = '-p';
			$args[] = $parent->getHash();
		}

		return new Commit($this, trim($this->run('commit-tree', ...$args)));
	}

	/**
	 * @template T of GitObjectInterface
	 *
	 * @param class-string<T> $type
	 *
	 * @return T
	 */
	public function createObject(string $contents, string $type = File::class): GitObjectInterface
	{
		if (!is_a($type, GitObjectInterface::class, true)) {
			throw new \InvalidArgumentException(sprintf('$type must be a class string of type "%s", "%s" given', GitObject::class, $type));
		}

		$hash = trim($this->runInput($contents, 'hash-object -w --stdin -t', $type::getTypeName()));

		/** @var class-string<T> $type */
		return new $type($this, $hash);
	}

	/**
	 * @param class-string<GitObject> $type
	 */
	public function readObject(string $hash, string $type = File::class): string
	{
		if (!is_a($type, GitObject::class, true)) {
			throw new \InvalidArgumentException(sprintf('$type must be a class string of type "%s", "%s" given', GitObject::class, $type));
		}

		return $this->run('cat-file', $type::getTypeName(), $hash);
	}

	public function pushCommit(Commit $commit, string $branchName, bool $force = false): self
	{
		if ($branchName === 'HEAD') {
			$branchName = $this->getHeadBranchName();
		}

		$this->run(
			'push origin --progress',
			'--'.($force ? '' : 'no-').'force-with-lease',
			$commit->getHash().':refs/heads/'.$branchName
		);

		return $this;
	}

	public function setAuthor(string $name, string $email): static
	{
		$this->run('config user.name', $name);
		$this->run('config user.email', $email);

		return $this;
	}

	private function initialize(string $url): void
	{
		$this->executable->execute(['init', '--bare', $this->gitDir]);
		$this->run('remote add origin', $url);
		$this->run('config remote.origin.promisor true');
		$this->run('config remote.origin.partialclonefilter tree:0');
		$this->run('fetch origin --progress --no-tags --depth 1');
	}

	private function run(string $command, string ...$args): string
	{
		return $this->runInput('', $command, ...$args);
	}

	private function runInput(string $input, string $command, string ...$args): string
	{
		return $this->executable->execute([...explode(' ', $command), ...array_values($args)], $this->gitDir, $input);
	}

	private function createTempPath(?string $dir): string
	{
		/** @psalm-suppress TooManyArguments */
		$path = (new Filesystem)->tempnam($dir ?? sys_get_temp_dir(), 'repo', '.git');

		(new Filesystem)->remove($path);

		return $path;
	}
}

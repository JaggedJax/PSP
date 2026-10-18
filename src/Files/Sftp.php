<?php

namespace PSP\Files;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP as SftpConnection;

/**
 * SFTP (file transfer over SSH) client, with phpseclib. Logs in with a password or a private key,
 * and can check the server's host key fingerprint so a different server is refused. Transfers
 * stream from and to local files
 */
class Sftp implements RemoteFileClient
{
	private ?SftpConnection $connection = null;
	private ?string $host_key = null;

	/**
	 * @param string $host
	 * @param int $port
	 * @param int $timeout Seconds
	 */
	public function __construct(
		private readonly string $host,
		private readonly int $port = 22,
		private readonly int $timeout = 30,
	) {
	}

	/**
	 * Connect, check the host key, and log in. Must be called before the file operations
	 * @param string $username
	 * @param string $password Used when there is no private key, or as a second factor
	 * @param string $private_key OpenSSH or PEM private key text. Empty to log in with the password
	 * @param string $key_passphrase
	 * @param string $expected_fingerprint "SHA256:..." as shown by ssh-keygen -lf. Empty to not check
	 * @throws SftpException
	 */
	public function login(string $username, string $password = '', string $private_key = '', string $key_passphrase = '', string $expected_fingerprint = ''): void
	{
		if (!class_exists(SftpConnection::class)) {
			throw new SftpException('SFTP needs the phpseclib/phpseclib package');
		}
		try {
			$sftp = new SftpConnection($this->host, $this->port, $this->timeout);
			$host_key = $sftp->getServerPublicHostKey();
		}
		catch (\Throwable $e) {
			throw new SftpException("Unable to connect to SFTP host {$this->host}:{$this->port}: " . $e->getMessage(), 0, $e);
		}
		if ($host_key === false) {
			throw new SftpException("Unable to connect to SFTP host {$this->host}:{$this->port}");
		}
		$this->host_key = $host_key;

		if ($expected_fingerprint !== '' && !self::fingerprint_matches($expected_fingerprint, $host_key)) {
			$sftp->disconnect();
			throw new SftpException("The SFTP server's host key fingerprint is " . self::fingerprint($host_key) . ", not the saved $expected_fingerprint. The connection was refused. If the server's key changed, confirm the new fingerprint with the server owner and save it.");
		}

		if ($private_key !== '') {
			try {
				$key = PublicKeyLoader::load($private_key, $key_passphrase !== '' ? $key_passphrase : false);
			}
			catch (\Throwable $e) {
				$sftp->disconnect();
				throw new SftpException('The SFTP private key could not be read. Check the key and its passphrase.', 0, $e);
			}
		}
		try {
			if ($private_key !== '') {
				$logged_in = $sftp->login($username, $key) || ($password !== '' && $sftp->login($username, $password));
			}
			else {
				$logged_in = $sftp->login($username, $password);
			}
		}
		catch (\Throwable $e) {
			throw new SftpException("SFTP login failed for user $username on {$this->host}:{$this->port}: " . $e->getMessage(), 0, $e);
		}
		if (!$logged_in) {
			$sftp->disconnect();
			throw new SftpException("SFTP login failed for user $username on {$this->host}:{$this->port}");
		}
		$this->connection = $sftp;
	}

	/**
	 * @return string|null The server's host key fingerprint, after login() connected
	 */
	public function host_fingerprint(): ?string
	{
		return $this->host_key !== null ? self::fingerprint($this->host_key) : null;
	}

	/**
	 * OpenSSH SHA256 fingerprint of a public key line
	 * @param string $public_key Example: "ssh-ed25519 AAAAC3Nza..."
	 * @return string Example: "SHA256:uNiVztksCsDhcc0u9e8BujQXVUpKZIDTMczCvj3tD2s"
	 */
	public static function fingerprint(string $public_key): string
	{
		$parts = preg_split('/\s+/', trim($public_key));
		$blob = base64_decode($parts[1] ?? $parts[0], true);
		return 'SHA256:' . rtrim(base64_encode(hash('sha256', (string)$blob, true)), '=');
	}

	/**
	 * @param string $expected With or without "SHA256:" and the trailing "="
	 * @param string $public_key
	 * @return bool
	 */
	public static function fingerprint_matches(string $expected, string $public_key): bool
	{
		$normalize = fn (string $fingerprint) => rtrim(preg_replace('/^SHA256:/i', '', trim($fingerprint)), '=');
		return hash_equals($normalize(self::fingerprint($public_key)), $normalize($expected));
	}

	/**
	 * @return SftpConnection
	 * @throws SftpException
	 */
	private function connection(): SftpConnection
	{
		if ($this->connection === null) {
			throw new SftpException('SFTP operation called before login()');
		}
		return $this->connection;
	}

	/**
	 * @inheritDoc
	 */
	public function upload_file(string $local_path, string $remote_path): void
	{
		if (!is_readable($local_path)) {
			throw new SftpException("Unable to open local file for upload: $local_path");
		}
		try {
			$success = $this->connection()->put($remote_path, $local_path, SftpConnection::SOURCE_LOCAL_FILE);
		}
		catch (SftpException $e) {
			throw $e;
		}
		catch (\Throwable $e) {
			throw new SftpException("Failed to upload $remote_path to {$this->host}: " . $e->getMessage(), 0, $e);
		}
		if (!$success) {
			throw new SftpException("Failed to upload $remote_path to {$this->host}");
		}
	}

	/**
	 * @inheritDoc
	 */
	public function list_files(string $path, string $pattern = '*'): array
	{
		try {
			$entries = $this->connection()->nlist($path);
		}
		catch (SftpException $e) {
			throw $e;
		}
		catch (\Throwable $e) {
			throw new SftpException("Failed to list $path on {$this->host}: " . $e->getMessage(), 0, $e);
		}
		if ($entries === false) {
			return [];
		}
		return array_values(array_filter(
			array_map('basename', $entries),
			fn (string $name) => $name !== '.' && $name !== '..' && fnmatch($pattern, $name)
		));
	}

	/**
	 * @inheritDoc
	 */
	public function download_file(string $remote_path, string $local_path): void
	{
		try {
			$success = $this->connection()->get($remote_path, $local_path);
		}
		catch (SftpException $e) {
			throw $e;
		}
		catch (\Throwable $e) {
			throw new SftpException("Failed to download $remote_path from {$this->host}: " . $e->getMessage(), 0, $e);
		}
		if ($success === false) {
			throw new SftpException("Failed to download $remote_path from {$this->host}");
		}
	}

	/**
	 * @inheritDoc
	 */
	public function move_file(string $from, string $to): bool
	{
		if ($this->rename_file($from, $to)) {
			return true;
		}
		try {
			$this->connection()->mkdir(dirname($to), -1, true);
		}
		catch (\Throwable) {
			return false;
		}
		return $this->rename_file($from, $to);
	}

	/**
	 * @inheritDoc
	 */
	public function rename_file(string $from, string $to): bool
	{
		try {
			return $this->connection()->rename($from, $to);
		}
		catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function delete_file(string $remote_path): bool
	{
		try {
			return $this->connection()->delete($remote_path, false);
		}
		catch (\Throwable) {
			return false;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function file_exists(string $remote_path): bool
	{
		try {
			return $this->connection()->is_file($remote_path);
		}
		catch (SftpException $e) {
			throw $e;
		}
		catch (\Throwable $e) {
			throw new SftpException("Failed to check $remote_path on {$this->host}: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function path_exists(string $path): bool
	{
		try {
			return $this->connection()->is_dir($path);
		}
		catch (SftpException $e) {
			throw $e;
		}
		catch (\Throwable $e) {
			throw new SftpException("Failed to check $path on {$this->host}: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function close(): void
	{
		if ($this->connection !== null) {
			$this->connection->disconnect();
			$this->connection = null;
		}
	}

	public function __destruct()
	{
		$this->close();
	}
}

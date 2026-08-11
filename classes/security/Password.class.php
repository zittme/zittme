<?php

/**
 * @deprecated
 */
class Password
{
	public static function registerCustomAlgorithm($name, $regexp, $callback)
	{
		Zittme\Framework\Password::addAlgorithm($name, $regexp, $callback);
	}

	public static function getSupportedAlgorithms()
	{
		return Zittme\Framework\Password::getSupportedAlgorithms();
	}

	public static function getBestAlgorithm()
	{
		return Zittme\Framework\Password::getBestSupportedAlgorithm();
	}

	public static function getCurrentlySelectedAlgorithm()
	{
		return Zittme\Framework\Password::getDefaultAlgorithm();
	}

	public static function getWorkFactor()
	{
		return Zittme\Framework\Password::getWorkFactor();
	}

	public static function createHash($password, $algorithm = null)
	{
		return Zittme\Framework\Password::hashPassword($password, $algorithm);
	}

	public static function checkPassword($password, $hash, $algorithm = null)
	{
		return Zittme\Framework\Password::checkPassword($password, $hash, $algorithm);
	}

	public static function checkAlgorithm($hash)
	{
		$algos = Zittme\Framework\Password::checkAlgorithm($hash);
		return count($algos) ? $algos[0] : false;
	}

	public static function checkWorkFactor($hash)
	{
		return Zittme\Framework\Password::checkWorkFactor($hash);
	}

	public static function createSecureSalt($length, $format = 'hex')
	{
		return Zittme\Framework\Security::getRandom($length, $format);
	}

	public static function createTemporaryPassword($length = 16)
	{
		return Zittme\Framework\Password::getRandomPassword($length);
	}

	public static function createSignature($string)
	{
		return Zittme\Framework\Security::createSignature($string);
	}

	public static function checkSignature($string, $signature)
	{
		return Zittme\Framework\Security::verifySignature($string, $signature);
	}

	public static function getSecretKey()
	{
		return config('crypto.authentication_key');
	}

	public static function pbkdf2($password, $salt, $algorithm = 'sha256', $iterations = 8192, $length = 24)
	{
		$hash = Zittme\Framework\Security::pbkdf2($password, $salt, $algorithm, $iterations, $length);
		$hash = explode(':', $hash);
		return base64_decode($hash[3]);
	}

	public static function bcrypt($password, $salt = null)
	{
		return Zittme\Framework\Security::bcrypt($password, $salt);
	}

	public static function strcmpConstantTime($a, $b)
	{
		return Zittme\Framework\Security::compareStrings($a, $b);
	}
}

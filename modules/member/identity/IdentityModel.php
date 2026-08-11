<?php

namespace Zittme\Modules\Member\Identity;

/**
 * Data access for verified identities (member_identity table).
 * CI is unique per person — used to block duplicate signups when the admin
 * enables 중복가입 방지.
 */
class IdentityModel
{
	public static function getInstance()
	{
		static $instance = null;
		if ($instance === null)
		{
			$instance = new static();
		}
		return $instance;
	}

	/**
	 * The stored identity record for a member, or null.
	 */
	public function getByMemberSrl(int $member_srl)
	{
		$args = new \stdClass();
		$args->member_srl = $member_srl;
		$output = executeQuery('member.getMemberIdentityByMemberSrl', $args);
		if (!$output->toBool() || !$output->data)
		{
			return null;
		}
		return is_array($output->data) ? array_shift($output->data) : $output->data;
	}

	/**
	 * The identity record holding this CI, or null.
	 */
	public function getByCi(string $ci)
	{
		if ($ci === '')
		{
			return null;
		}
		$args = new \stdClass();
		$args->ci = $ci;
		$output = executeQuery('member.getMemberIdentityByCi', $args);
		if (!$output->toBool() || !$output->data)
		{
			return null;
		}
		return is_array($output->data) ? array_shift($output->data) : $output->data;
	}

	/**
	 * Whether this CI is already registered to a DIFFERENT member.
	 */
	public function isCiUsedByOther(string $ci, int $member_srl): bool
	{
		$record = $this->getByCi($ci);
		return $record !== null && (int)$record->member_srl !== $member_srl;
	}

	/**
	 * Insert or update the identity record for a member (1 member = 1 row).
	 */
	public function saveForMember(int $member_srl, array $result)
	{
		$args = new \stdClass();
		$args->member_srl = $member_srl;
		$args->provider = mb_substr((string)($result['provider'] ?? ''), 0, 20);
		$args->ci = mb_substr((string)($result['ci'] ?? ''), 0, 100);
		$args->di = mb_substr((string)($result['di'] ?? ''), 0, 100);
		$args->name = mb_substr((string)($result['name'] ?? ''), 0, 80);
		$args->birthday = mb_substr((string)($result['birthday'] ?? ''), 0, 8);
		$args->sex = mb_substr((string)($result['sex'] ?? ''), 0, 1);
		$args->phone = mb_substr((string)($result['phone'] ?? ''), 0, 20);
		$args->telecom = mb_substr((string)($result['telecom'] ?? ''), 0, 20);
		$args->tid = mb_substr((string)($result['tid'] ?? ''), 0, 60);
		$args->regdate = date('YmdHis');

		if ($this->getByMemberSrl($member_srl))
		{
			return executeQuery('member.updateMemberIdentity', $args);
		}
		return executeQuery('member.insertMemberIdentity', $args);
	}

	/**
	 * Remove the identity record (member withdrawal etc.).
	 */
	public function deleteByMemberSrl(int $member_srl)
	{
		$args = new \stdClass();
		$args->member_srl = $member_srl;
		return executeQuery('member.deleteMemberIdentity', $args);
	}

	/**
	 * Whether a member has a verified identity of at least the given age.
	 */
	public function isAdultVerified(int $member_srl, int $age_limit = 19): bool
	{
		$record = $this->getByMemberSrl($member_srl);
		if (!$record || empty($record->birthday))
		{
			return false;
		}
		$age = Base::getAgeFromBirthday((string)$record->birthday);
		return $age !== null && $age >= $age_limit;
	}
}

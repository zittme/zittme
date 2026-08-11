<?php

namespace Zittme\Modules\Member\Social;

/**
 * Data access for linked social accounts (member_sns_account table).
 */
class AccountModel
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
	 * Find a linked account by provider + provider-side user id.
	 */
	public function getAccount(string $sns_type, string $sns_id)
	{
		$args = new \stdClass();
		$args->sns_type = $sns_type;
		$args->sns_id = $sns_id;
		$output = executeQuery('member.getMemberSnsAccount', $args);
		if (!$output->toBool() || !$output->data)
		{
			return null;
		}
		return is_array($output->data) ? array_shift($output->data) : $output->data;
	}

	/**
	 * Store a linked account. Values are truncated to column limits so a long
	 * display name / profile URL never fails the INSERT under STRICT mode.
	 */
	public function insertAccount(array $args)
	{
		if (isset($args['sns_type']))          $args['sns_type']          = mb_substr((string)$args['sns_type'], 0, 20);
		if (isset($args['sns_id']))            $args['sns_id']            = mb_substr((string)$args['sns_id'], 0, 100);
		if (isset($args['sns_name']))          $args['sns_name']          = mb_substr((string)$args['sns_name'], 0, 40);
		if (isset($args['sns_email']))         $args['sns_email']         = mb_substr((string)$args['sns_email'], 0, 250);
		if (isset($args['sns_profile_image'])) $args['sns_profile_image'] = mb_substr((string)$args['sns_profile_image'], 0, 250);

		return executeQuery('member.insertMemberSnsAccount', (object)$args);
	}

	/**
	 * All linked accounts for a member.
	 */
	public function getAccountsByMemberSrl(int $member_srl)
	{
		$args = new \stdClass();
		$args->member_srl = $member_srl;
		$output = executeQueryArray('member.getMemberSnsAccountsByMemberSrl', $args);
		if (!$output->toBool() || !$output->data)
		{
			return [];
		}
		return is_array($output->data) ? $output->data : [$output->data];
	}

	/**
	 * Unlink a provider from a member.
	 */
	public function deleteAccount(int $member_srl, string $sns_type = '')
	{
		$args = new \stdClass();
		$args->member_srl = $member_srl;
		if ($sns_type !== '')
		{
			$args->sns_type = $sns_type;
		}
		return executeQuery('member.deleteMemberSnsAccount', $args);
	}
}

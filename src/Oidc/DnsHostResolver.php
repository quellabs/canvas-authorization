<?php

	namespace Quellabs\CanvasAuthorization\Oidc;

	/**
	 * Default HostResolverInterface implementation, backed by PHP's DNS functions.
	 */
	class DnsHostResolver implements HostResolverInterface {

		/**
		 * Resolve both A and AAAA records for the given host.
		 * @param string $host
		 * @return string[]
		 */
		public function resolve(string $host): array {
			$ips = [];

			// @-suppressed: dns_get_record() emits a warning on NXDOMAIN/lookup failure,
			// which this method reports by returning an empty array instead
			$aRecords = @dns_get_record($host, DNS_A);

			if (is_array($aRecords)) {
				foreach ($aRecords as $record) {
					if (isset($record['ip']) && is_string($record['ip'])) {
						$ips[] = $record['ip'];
					}
				}
			}

			$aaaaRecords = @dns_get_record($host, DNS_AAAA);

			if (is_array($aaaaRecords)) {
				foreach ($aaaaRecords as $record) {
					if (isset($record['ipv6']) && is_string($record['ipv6'])) {
						$ips[] = $record['ipv6'];
					}
				}
			}

			return $ips;
		}
	}

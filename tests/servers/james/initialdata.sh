#!/bin/bash
# Replaces the demo image's /root/initialdata.sh (run by startup.sh once the JMX port is up):
# provisions the fixed test account of the php-imap server matrix.
james-cli adddomain "${TEST_DOMAIN:-example.local}"
james-cli adduser "${TEST_USER:-test}@${TEST_DOMAIN:-example.local}" "$TEST_PASSWORD"

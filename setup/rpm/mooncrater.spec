Name:           mooncrater
Version:        1.50
Release:        1%{?dist}
Summary:        Linux infrastructure inventory and migration portal
License:        AGPL-3.0-or-later
URL:            https://github.com/markhawks/MoonCrater
Source0:        %{name}-%{version}.tar.gz
BuildArch:      noarch

Requires:       httpd, php, php-cli, php-fpm, php-pgsql, php-mbstring
Requires:       postgresql, postgresql-server, policycoreutils-python-utils
Requires:       firewalld, openssh-server, systemd, util-linux, hostname

%description
MoonCrater correlates Satellite and external Linux inventories and tracks RHEL
migrations. Run mooncrater-setup after installing on a fresh server.

%prep
%autosetup

%build

%install
install -d %{buildroot}/opt/mooncrater %{buildroot}%{_sbindir}
cp -a app bin docs migrations public setup tests %{buildroot}/opt/mooncrater/
install -d -m 0750 %{buildroot}/opt/mooncrater/var/backups %{buildroot}/opt/mooncrater/var/uploads
install -m 0755 setup/rpm/mooncrater-setup %{buildroot}%{_sbindir}/mooncrater-setup
install -m 0644 README.md CHANGELOG.md SECURITY.md CONTRIBUTING.md LICENSE .env.example %{buildroot}/opt/mooncrater/

%files
%license LICENSE
%doc README.md CHANGELOG.md
%{_sbindir}/mooncrater-setup
/opt/mooncrater

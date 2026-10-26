import importlib.util, pathlib, unittest
root=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('patch',root/'deploy/fix-navigation-405.py')
patch=importlib.util.module_from_spec(spec);spec.loader.exec_module(patch)
class NavigationPatch(unittest.TestCase):
    def setUp(self): self.conf=(root/'deploy/nginx.conf.example').read_text()
    def test_preserves_api_and_socket(self):
        result=patch.patched(self.conf)
        self.assertIn('fastcgi_pass unix:/run/php/php8.3-fpm.sock;',result)
        self.assertIn('limit_req zone=m3l_api burst=10 nodelay;',result)
        self.assertIn('location ~ \\.php$ { return 404; }',result)
        self.assertEqual(result.count('location = /v2/ {'),1)
        self.assertIn('error_page 405 = @m3l_navigation_recovery;',result)
    def test_idempotent(self):
        result=patch.patched(self.conf)
        self.assertEqual(patch.patched(result).count(patch.START),1)
    def test_custom_route_rejected(self):
        with self.assertRaises(ValueError):patch.patched(self.conf+'\nlocation = /v2/ { return 403; }')
    def test_ambiguous_socket_rejected(self):
        with self.assertRaises(ValueError):patch.patched(self.conf.replace('fastcgi_pass unix:/run/php/php8.3-fpm.sock;',''))
    def test_live_php85_preserved(self):
        self.assertIn('fastcgi_pass unix:/run/php/php8.5-fpm.sock;',patch.patched(self.conf.replace('php8.3-fpm','php8.5-fpm')))
unittest.main()

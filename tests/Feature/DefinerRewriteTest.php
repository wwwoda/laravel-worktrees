<?php

use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Shared\MariaDbServer;

/*
 * The lines below are taken from mariadb-dump 10.11 and mysqldump 8.4 output of a
 * database with a view, a trigger, an event, a function and a procedure, all defined
 * by `old_sail`, and a table whose text values look like definer clauses.
 */
const DEFINER_DUMP = <<<'SQL'
INSERT INTO `snippets` VALUES
('DEFINER=`old_sail`@`localhost`'),
('a\nCREATE DEFINER=`old_sail`@`localhost` PROCEDURE p'),
('/*!50013 DEFINER=`old_sail`@`localhost` SQL SECURITY DEFINER */');
INSERT INTO `snippets` VALUES ('DEFINER=`old_sail`@`localhost`'),('/*!50013 DEFINER=`old_sail`@`localhost` SQL SECURITY DEFINER */');
/*!50003 CREATE*/ /*!50017 DEFINER=`old_sail`@`localhost`*/ /*!50003 TRIGGER t BEFORE INSERT ON snippets FOR EACH ROW SET NEW.body = 'DEFINER=`old_sail`@`localhost`'
*/;;
/*!50106 CREATE*/ /*!50117 DEFINER=`old_sail`@`localhost`*/ /*!50106 EVENT `e` ON SCHEDULE EVERY 1 DAY DO SELECT 1
*/ ;;
/*!50003 CREATE*/ /*!50020 DEFINER=`old_sail`@`localhost`*/ /*!50003 PROCEDURE `old`()
SELECT 1 */;;
CREATE DEFINER=`old_sail`@`localhost` FUNCTION `f`() RETURNS int(11)
CREATE DEFINER=`old_sail`@`localhost` PROCEDURE `p`()
SELECT 'DEFINER=`old_sail`@`localhost`'
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`old_sail`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v` AS select `snippets`.`body` AS `body` from `snippets` */;
SQL;

const DEFINER_EXPECTED = <<<'SQL'
INSERT INTO `snippets` VALUES
('DEFINER=`old_sail`@`localhost`'),
('a\nCREATE DEFINER=`old_sail`@`localhost` PROCEDURE p'),
('/*!50013 DEFINER=`old_sail`@`localhost` SQL SECURITY DEFINER */');
INSERT INTO `snippets` VALUES ('DEFINER=`old_sail`@`localhost`'),('/*!50013 DEFINER=`old_sail`@`localhost` SQL SECURITY DEFINER */');
/*!50003 CREATE*/ /*!50017 DEFINER=`wt_user`@`%`*/ /*!50003 TRIGGER t BEFORE INSERT ON snippets FOR EACH ROW SET NEW.body = 'DEFINER=`old_sail`@`localhost`'
*/;;
/*!50106 CREATE*/ /*!50117 DEFINER=`wt_user`@`%`*/ /*!50106 EVENT `e` ON SCHEDULE EVERY 1 DAY DO SELECT 1
*/ ;;
/*!50003 CREATE*/ /*!50020 DEFINER=`wt_user`@`%`*/ /*!50003 PROCEDURE `old`()
SELECT 1 */;;
CREATE DEFINER=`wt_user`@`%` FUNCTION `f`() RETURNS int(11)
CREATE DEFINER=`wt_user`@`%` PROCEDURE `p`()
SELECT 'DEFINER=`old_sail`@`localhost`'
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`wt_user`@`%` SQL SECURITY DEFINER */
/*!50001 VIEW `v` AS select `snippets`.`body` AS `body` from `snippets` */;
SQL;

test('the definer rewrite changes the definer clauses of a dump and keeps text values', function () {
    $file = tempnam(sys_get_temp_dir(), 'lw-definer-');
    file_put_contents($file, DEFINER_DUMP."\n");
    $script = (new ReflectionMethod(MariaDbServer::class, 'definerRewrite'))->invoke(null, 'wt_user');

    $result = Process::run(['sed', '-E', $script, $file]);
    unlink($file);

    expect($result->successful())->toBeTrue()
        ->and($result->output())->toBe(DEFINER_EXPECTED."\n");
});

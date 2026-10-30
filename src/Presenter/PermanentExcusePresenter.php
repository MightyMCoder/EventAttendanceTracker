<?php
/**
 * Builds the per-configuration permanent-excuse settings.
 *
 * @copyright MighryMCoder
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 */

namespace AdmidioPlugin\EventAttendance\Presenter;

use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\UI\Component\DataTables;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use Admidio\Users\Entity\User;
use AdmidioPlugin\EventAttendance\Service\PermanentExcuseService;

final class PermanentExcusePresenter
{
    /**
     * Add the staged rule list, native editor controls and asynchronous table initialization.
     *
     * @param array<string,mixed> $tracker The configuration whose eligible members are shown.
     * @return void
     */
    public function create(PagePresenter $page, Plugin $plugin, array $tracker, FormPresenter $form): void
    {
        global $gDb, $gProfileFields, $gL10n, $gSettingsManager;
        $members = $gDb->queryPrepared(
            'SELECT DISTINCT mem_usr_id FROM ' . TABLE_PREFIX . '_event_attendance_tracker_roles
             INNER JOIN ' . TBL_MEMBERS . ' ON mem_rol_id = etr_rol_id
             WHERE etr_tracker_id = ? AND mem_end > CURRENT_DATE
               AND (mem_approved IS NULL OR mem_approved < 3)',
            array((int) $tracker['eat_id'])
        )->fetchAll();
        $names = array();
        $name = static function (int $id) use ($gDb, $gProfileFields): string {
            $user = new User($gDb, $gProfileFields, $id);
            return trim($user->getValue('FIRST_NAME') . ' ' . $user->getValue('LAST_NAME'));
        };
        foreach ($members as $member) {
            $id = (int) $member['mem_usr_id'];
            $names[$id] = $name($id);
        }
        $rules = self::rows((int) $tracker['eat_id']);
        foreach ($rules as $rule) {
            $names[(int) $rule['eax_usr_id']] = $rule['name'];
        }
        asort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $form->addInput('tracker_permanent_excuses', '', json_encode(array_map(static fn (array $rule): array => array(
            'id' => (int) $rule['eax_id'], 'user' => (int) $rule['eax_usr_id'],
            'from' => $rule['eax_date_from'], 'until' => $rule['eax_date_until']
        ), $rules), JSON_THROW_ON_ERROR), array('property' => FormPresenter::FIELD_HIDDEN));
        $form->addInput('excuse_id', '', '0', array('property' => FormPresenter::FIELD_HIDDEN));
        $form->addSelectBox('excuse_user', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSE_PERSON'), $names,
            array('search' => true, 'property' => FormPresenter::FIELD_REQUIRED));
        $form->addInput('excuse_from', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSE_FROM'),
            (new \DateTimeImmutable(DATETIME_NOW))->format($gSettingsManager->getString('system_date')),
            array('type' => 'date', 'property' => FormPresenter::FIELD_REQUIRED));
        $form->addInput('excuse_until', $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSE_UNTIL'), '', array('type' => 'date'));
        $page->assignSmartyVariable('permanentExcuseActions', array(
            'uuid' => 'permanent_excuse_ROWID',
            'actions' => array(
                array('url' => 'javascript:window.eventAttendanceEditPermanentExcuse(ROWID);', 'icon' => 'bi bi-pencil-square', 'tooltip' => $gL10n->get('SYS_EDIT')),
                array('dataHref' => 'window.eventAttendanceDeletePermanentExcuse(ROWID);', 'dataMessage' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSE_DELETE_CONFIRM'), 'icon' => 'bi bi-trash', 'tooltip' => $gL10n->get('SYS_DELETE'))
            )
        ));

        $table = new DataTables($page, 'adm_event_attendance_excuses_table');
        $table->disableColumnsSort(array(4));
        $table->setColumnsNotHideResponsive(array(1, 4));
        $table->setOrderColumns(array(array(1, 'asc')));
        $table->setRowsPerPage(10);
        $page->addJavascriptFile(ADMIDIO_URL . FOLDER_PLUGINS . '/' . $plugin->id . '/js/permanent-excuses.js');
        // Register before initialization: Admidio loads the table language asynchronously.
        $page->addJavascript('$("#adm_event_attendance_excuses_table").one("init.dt", function () {
            window.initEventAttendancePermanentExcuses(' . json_encode(array(
            'rules' => $rules,
            'today' => (new \DateTimeImmutable(DATETIME_NOW))->format('Y-m-d'),
            'dateFormat' => $gSettingsManager->getString('system_date'),
            'language' => $gL10n->getLanguageLibs(),
            'unlimited' => $gL10n->get('PLG_EVENT_ATTENDANCE_TRACKER_PERMANENT_EXCUSE_UNLIMITED'),
        ), JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG) . ');
        });', true);
        // This table grows dynamically, so pagination must also work when it starts empty.
        $table->createJavascript(max(11, count($rules)), 4);
    }

    /** Return display and editor values without changing the stored ISO dates. */
    public static function rows(int $trackerId): array
    {
        global $gDb, $gProfileFields, $gSettingsManager;

        $rules = (new PermanentExcuseService($gDb))->findAll($trackerId);
        foreach ($rules as &$rule) {
            $user = new User($gDb, $gProfileFields, (int) $rule['eax_usr_id']);
            $rule['name'] = trim($user->getValue('FIRST_NAME') . ' ' . $user->getValue('LAST_NAME'));
            $rule['fromDisplay'] = (new \DateTimeImmutable($rule['eax_date_from']))->format($gSettingsManager->getString('system_date'));
            $rule['untilDisplay'] = $rule['eax_date_until'] === null ? null
                : (new \DateTimeImmutable($rule['eax_date_until']))->format($gSettingsManager->getString('system_date'));
        }
        unset($rule);
        return $rules;
    }
}

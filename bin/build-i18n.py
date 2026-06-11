#!/usr/bin/env python3
"""Builds languages/wfcp.pot, wfcp-fa_IR.po and compiles wfcp-fa_IR.mo.

Run from the plugin root:  python3 bin/build-i18n.py
"""
import re, glob, struct, collections, datetime

FA = {
 '123Admin requires PHP %s or newer. The plugin is inactive.': '123Admin به PHP نسخه %s یا جدیدتر نیاز دارد. افزونه غیرفعال است.',
 'Your account has been suspended. Please contact the store.': 'حساب شما مسدود شده است. لطفاً با فروشگاه تماس بگیرید.',
 'User not found.': 'کاربر یافت نشد.',
 'Only administrators can edit administrator accounts.': 'فقط مدیران کل می‌توانند حساب مدیران را ویرایش کنند.',
 'Unknown role.': 'نقش ناشناخته.',
 'You cannot assign this role.': 'شما اجازه اختصاص این نقش را ندارید.',
 'This account cannot be blocked.': 'این حساب قابل مسدودسازی نیست.',
 'Note cannot be empty.': 'یادداشت نمی‌تواند خالی باشد.',
 'Guest': 'مهمان',
 'Order not found.': 'سفارش یافت نشد.',
 'Invalid order status.': 'وضعیت سفارش نامعتبر است.',
 'Order or product not found.': 'سفارش یا محصول یافت نشد.',
 'Order item not found.': 'آیتم سفارش یافت نشد.',
 'Select between 1 and 200 orders.': 'بین ۱ تا ۲۰۰ سفارش انتخاب کنید.',
 'You do not have permission to do this.': 'شما مجوز انجام این کار را ندارید.',
 'Unknown bulk action.': 'عملیات گروهی ناشناخته.',
 'Product name is required.': 'نام محصول الزامی است.',
 'Product not found.': 'محصول یافت نشد.',
 'Select between 1 and 200 products.': 'بین ۱ تا ۲۰۰ محصول انتخاب کنید.',
 'Variable product not found.': 'محصول متغیر یافت نشد.',
 'Variation not found.': 'متغیر یافت نشد.',
 'You do not have permission to change prices.': 'شما مجوز تغییر قیمت را ندارید.',
 'You do not have permission to change stock.': 'شما مجوز تغییر موجودی را ندارید.',
 'Unknown report type.': 'نوع گزارش ناشناخته.',
 'Authentication required.': 'ورود به سیستم الزامی است.',
 'Only administrators can manage panel settings.': 'فقط مدیران کل می‌توانند تنظیمات پنل را مدیریت کنند.',
 'System': 'سیستم',
 'Dashboard': 'داشبورد',
 'Products': 'محصولات',
 'Orders': 'سفارشات',
 'Customers': 'مشتریان',
 'Reports': 'گزارشات',
 'Settings': 'تنظیمات',
 'Log out': 'خروج',
 'WP Admin': 'پیشخوان وردپرس',
 'Search everything…': 'جستجوی سراسری…',
 'Theme': 'پوسته',
 'Auto': 'خودکار',
 'Light': 'روشن',
 'Dark': 'تیره',
 'Save': 'ذخیره',
 'Cancel': 'انصراف',
 'Delete': 'حذف',
 'Edit': 'ویرایش',
 'View': 'مشاهده',
 'Create': 'ایجاد',
 'Duplicate': 'کپی',
 'Print': 'چاپ',
 'Export CSV': 'خروجی CSV',
 'Search': 'جستجو',
 'Filters': 'فیلترها',
 'All': 'همه',
 'None': 'هیچ‌کدام',
 'Yes': 'بله',
 'No': 'خیر',
 'Loading…': 'در حال بارگذاری…',
 'Nothing found.': 'موردی یافت نشد.',
 'Saved.': 'ذخیره شد.',
 'Deleted.': 'حذف شد.',
 'Something went wrong.': 'مشکلی پیش آمد.',
 'Are you sure you want to delete this? This cannot be undone.': 'آیا از حذف مطمئن هستید؟ این عمل قابل بازگشت نیست.',
 'Apply this action to all selected items?': 'این عملیات روی همه موارد انتخاب‌شده اعمال شود؟',
 'selected': 'انتخاب شده',
 'Bulk actions': 'عملیات گروهی',
 'Apply': 'اعمال',
 'Previous': 'قبلی',
 'Next': 'بعدی',
 'Page': 'صفحه',
 'of': 'از',
 'Actions': 'عملیات',
 'Close': 'بستن',
 'Copied.': 'کپی شد.',
 'Press / to search, g then a key to navigate.': 'برای جستجو / و برای پیمایش g سپس یک کلید را بزنید.',
 'You appear to be offline.': 'به نظر می‌رسد آفلاین هستید.',
 'Name': 'نام',
 'Date': 'تاریخ',
 'Status': 'وضعیت',
 'Total': 'مجموع',
 'Qty': 'تعداد',
 'Note': 'یادداشت',
 'Add note': 'افزودن یادداشت',
 'Notes': 'یادداشت‌ها',
 'Email': 'ایمیل',
 'Phone': 'موبایل',
 'Role': 'نقش',
 'Addresses': 'آدرس‌ها',
 'Sales today': 'فروش امروز',
 'Sales this week': 'فروش هفته',
 'Sales this month': 'فروش ماه',
 'Total revenue': 'درآمد کل',
 'Orders today': 'سفارشات امروز',
 'Pending orders': 'سفارشات معوق',
 'Processing': 'در حال پردازش',
 'Completed': 'تکمیل شده',
 'Low stock': 'کم موجود',
 'Out of stock': 'ناموجود',
 'Sales – last 30 days': 'فروش – ۳۰ روز اخیر',
 'Orders – last 30 days': 'سفارشات – ۳۰ روز اخیر',
 'Recent orders': 'سفارشات اخیر',
 'Recent activity': 'فعالیت‌های اخیر',
 'Add product': 'افزودن محصول',
 'Product name': 'نام محصول',
 'Regular price': 'قیمت عادی',
 'Sale price': 'قیمت ویژه',
 'Sale starts': 'شروع تخفیف',
 'Sale ends': 'پایان تخفیف',
 'SKU': 'شناسه (SKU)',
 'Stock': 'موجودی',
 'Manage stock': 'مدیریت موجودی',
 'Stock status': 'وضعیت موجودی',
 'In stock': 'موجود',
 'Categories': 'دسته‌بندی‌ها',
 'Tags': 'برچسب‌ها',
 'Short description': 'توضیحات کوتاه',
 'Description': 'توضیحات کامل',
 'Weight': 'وزن',
 'Dimensions (L×W×H)': 'ابعاد (طول×عرض×ارتفاع)',
 'Publish': 'انتشار',
 'Draft': 'پیش‌نویس',
 'No image': 'بدون تصویر',
 'Best sellers': 'پرفروش‌ها',
 'Slow movers': 'کم‌فروش‌ها',
 'On sale': 'تخفیف‌دار',
 'Variations': 'متغیرها',
 'Bulk price update': 'بروزرسانی گروهی قیمت',
 'Bulk stock update': 'بروزرسانی گروهی موجودی',
 'Change prices by % (e.g. 10 or -5)': 'تغییر قیمت‌ها به درصد (مثلاً 10 یا 5-)',
 'Set stock to': 'تنظیم موجودی به',
 'Print list': 'چاپ لیست',
 'Order': 'سفارش',
 'New order': 'سفارش جدید',
 'Order #, phone, email or name…': 'شماره سفارش، موبایل، ایمیل یا نام…',
 'Change status': 'تغییر وضعیت',
 'Items': 'اقلام',
 'Payment': 'پرداخت',
 'Shipping': 'حمل و نقل',
 'Billing': 'صورتحساب',
 'Customer': 'مشتری',
 'Today': 'امروز',
 'Yesterday': 'دیروز',
 'Invoice': 'فاکتور',
 'Shipping label': 'برچسب ارسال',
 'History': 'تاریخچه',
 'New order received!': 'سفارش جدید دریافت شد!',
 'Refunds': 'مرجوعی',
 'Discount': 'تخفیف',
 'Subtotal': 'جمع جزء',
 'Tax': 'مالیات',
 'Total spent': 'مجموع خرید',
 'Last login': 'آخرین ورود',
 'Block': 'مسدودسازی',
 'Unblock': 'رفع مسدودی',
 'Blocked': 'مسدود',
 'Registered': 'تاریخ عضویت',
 'Never': 'هرگز',
 'Sales': 'فروش',
 'Products report': 'گزارش محصولات',
 'Customers report': 'گزارش مشتریان',
 'Categories report': 'گزارش دسته‌بندی‌ها',
 'Stock report': 'گزارش موجودی',
 'Daily': 'روزانه',
 'Weekly': 'هفتگی',
 'Monthly': 'ماهانه',
 'Yearly': 'سالانه',
 'Gross sales': 'فروش ناخالص',
 'Net sales': 'فروش خالص',
 'Avg. order value': 'میانگین ارزش سفارش',
 'Items sold': 'اقلام فروخته‌شده',
 'Panel address (slug)': 'آدرس پنل (اسلاگ)',
 'The panel will be available at site.com/{slug}. Examples: store, panel, control, manager.': 'پنل در آدرس site.com/{slug} در دسترس خواهد بود. نمونه: store ،panel ،control ،manager.',
 'Roles with panel access': 'نقش‌های دارای دسترسی به پنل',
 'Permissions': 'مجوزها',
 'Users': 'کاربران',
 'Pricing': 'قیمت‌گذاری',
 'Export': 'صادرات',
 'Default theme': 'پوسته پیش‌فرض',
 'Rows per page': 'تعداد ردیف در صفحه',
 'Low stock threshold': 'آستانه کمبود موجودی',
 'Settings saved. If you changed the slug, the panel URL has changed.': 'تنظیمات ذخیره شد. اگر اسلاگ را تغییر داده‌اید، آدرس پنل عوض شده است.',
 'Audit log': 'لاگ فعالیت‌ها',
 'Open Panel': 'ورود به پنل',
 'You do not have permission to access this panel. Contact your store administrator.': 'شما مجوز دسترسی به این پنل را ندارید. با مدیر فروشگاه تماس بگیرید.',
 'Access denied': 'دسترسی غیرمجاز',
 'Store Panel': 'پنل فروشگاه',
 'Too many requests. Please slow down and try again shortly.': 'تعداد درخواست‌ها زیاد است. لطفاً کمی صبر کنید و دوباره تلاش کنید.',
 'Your session has expired. Reloading…': 'نشست شما منقضی شده است. در حال بارگذاری مجدد…',
 'The export was truncated. Refine your filters to export everything.': 'خروجی بریده شد و ناقص است. برای خروجی کامل، فیلترها را محدودتر کنید.',
}

pattern = re.compile(r"(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*'((?:[^'\\]|\\.)*)'\s*,\s*'wfcp'")
entries = collections.OrderedDict()
for f in sorted(glob.glob('**/*.php', recursive=True)):
    for i, line in enumerate(open(f, encoding='utf-8').read().splitlines(), 1):
        for m in pattern.finditer(line):
            s = m.group(1).replace("\\'", "'")
            entries.setdefault(s, []).append(f"{f}:{i}")

def po_escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')

now = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M%z')

def header(lang_block):
    return f'''msgid ""
msgstr ""
"Project-Id-Version: 123Admin 1.0.0\\n"
"Report-Msgid-Bugs-To: https://github.com/ninakhairunnisa/123admin/issues\\n"
"POT-Creation-Date: {now}\\n"
"PO-Revision-Date: {now}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Domain: wfcp\\n"
{lang_block}
'''

# POT
with open('languages/wfcp.pot', 'w', encoding='utf-8') as fh:
    fh.write(header('"Language-Team: 123Admin\\n"\n'))
    for s, refs in entries.items():
        fh.write('\n')
        for r in refs[:3]:
            fh.write(f'#: {r}\n')
        fh.write(f'msgid "{po_escape(s)}"\nmsgstr ""\n')

# fa_IR PO
missing = [s for s in entries if s not in FA]
if missing:
    print('WARNING missing fa translations:', missing)
with open('languages/wfcp-fa_IR.po', 'w', encoding='utf-8') as fh:
    fh.write(header('"Language: fa_IR\\n"\n"Plural-Forms: nplurals=2; plural=(n==0 || n==1) ? 0 : 1;\\n"\n'))
    for s, refs in entries.items():
        fh.write('\n')
        for r in refs[:3]:
            fh.write(f'#: {r}\n')
        fh.write(f'msgid "{po_escape(s)}"\nmsgstr "{po_escape(FA.get(s, ""))}"\n')

# Compile MO (GNU gettext binary format)
def write_mo(po_entries, path):
    keys = sorted(po_entries.keys())
    offsets, ids, strs = [], b'', b''
    for k in keys:
        msgid, msgstr = k.encode('utf-8'), po_entries[k].encode('utf-8')
        offsets.append((len(ids), len(msgid), len(strs), len(msgstr)))
        ids += msgid + b'\x00'
        strs += msgstr + b'\x00'
    n = len(keys)
    keystart = 7 * 4 + 16 * n
    valuestart = keystart + len(ids)
    koffsets, voffsets = [], []
    for o1, l1, o2, l2 in offsets:
        koffsets += [l1, o1 + keystart]
        voffsets += [l2, o2 + valuestart]
    output = struct.pack('Iiiiiii', 0x950412de, 0, n, 7 * 4, 7 * 4 + n * 8, 0, 0)
    output += struct.pack('i' * n * 2, *koffsets)
    output += struct.pack('i' * n * 2, *voffsets)
    output += ids + strs
    open(path, 'wb').write(output)

mo_entries = {'': 'Content-Type: text/plain; charset=UTF-8\nLanguage: fa_IR\nX-Domain: wfcp\n'}
for s in entries:
    if FA.get(s):
        mo_entries[s] = FA[s]
write_mo(mo_entries, 'languages/wfcp-fa_IR.mo')
print(f'Done: {len(entries)} strings, {len(mo_entries)-1} fa translations compiled.')

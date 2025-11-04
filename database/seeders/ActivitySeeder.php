<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Activity;
use App\Models\ActivityMedia;
use Carbon\Carbon;

class ActivitySeeder extends Seeder
{
    public function run()
    {
        // Cultural Activities (Type ID: 3)
        $this->seedCulturalActivities();
        
        // Training Courses (Type ID: 4)
        $this->seedTrainingCourses();
        
        // Social and Humanitarian (Type ID: 5)
        $this->seedSocialActivities();
        
        // Health and Humanitarian (Type ID: 2)
        $this->seedHealthActivities();
        
        // Visits and Agreements (Type ID: 6)
        $this->seedVisitsAndAgreements();
    }

    private function seedCulturalActivities()
    {
        // Day for Children
        $activity = Activity::create([
            'type_id' => 3,
            'title_en' => 'International Children\'s Day Celebration',
            'title_ar' => 'يوم على شرف الطفل',
            'description_en' => '<p>Under the motto "We are all with the children of the world in pain and dreams", TAGEMI organized celebrations for children\'s day with participation from associations across various municipalities of the wilaya. The event included cultural, artistic and recreational programs that were attended by more than 2,000 children and concluded with a meal in their honor.</p>',
            'description_ar' => '<p>من بين الطبعات المنظمة و تحت شعار: كلنا مع أطفال العالم في الآلام و الأحلام، و التي شاركت فيه جمعيات من مختلف بلديات الولاية، حيث شملت التظاهرة برنامج ثقافي ، فني و ترفيهي هادف و الذي حضر فيه أكثر من ألفين 2000 طفل واختتمت بوجبة غذاء على شرفهم.</p>',
            'location' => 'Ghardaia, Algeria',
            'event_date' => Carbon::parse('2022-06-01'),
            'status' => 'completed',
            'participants_count' => 2000,
            'featured' => true
        ]);

        // Day for Parents
        $activity2 = Activity::create([
            'type_id' => 3,
            'title_en' => 'Day for Parents - "And to Parents, Kindness"',
            'title_ar' => 'يوم على شرف الوالدين تحت شعار " و بالوالدين إحسانا"',
            'description_en' => '<p>In appreciation of Islamic and humanitarian values in caring for parents and the elderly, TAGEMI organizes a day in honor of parents where fathers gather with their children, aiming to spread the belief of honoring parents and maintaining the family system. Attended by more than 250 parents across different editions in Aflou, Al-Qarrara, Adrar, and Setif.</p>',
            'description_ar' => '<p>تثمينا للقيم الإسلامية و الإنسانية في رعاية الوالدين و المسنين، تنظم جمعية تـجـمي التظاهرة يوم على شرف الوالدين أين يجتمع الآباء مع الأبناء يهدف إلى نشر عقيدة بر الوالدين والمحافظة على منظومة الأسرة. بحضور أزيد من 250 والــــد في مختلف الطبعات.</p>',
            'location' => 'Multiple locations - Aflou, Al-Qarrara, Adrar, Setif',
            'event_date' => Carbon::parse('2023-03-15'),
            'status' => 'completed',
            'participants_count' => 250,
            'featured' => true
        ]);
    }

    private function seedTrainingCourses()
    {
        // Arabic Calligraphy Competition
        $activity = Activity::create([
            'type_id' => 4,
            'title_en' => 'Wilaya Arabic Calligraphy Competition',
            'title_ar' => 'تنظيم المسابقة الوالئية في الخط العربي',
            'description_en' => '<p>In coordination with the Mediterranean Manuscripts project "Manumed" in France, the association organized the Wilaya competition in Arabic calligraphy, and workshops in Arabic calligraphy were organized with supervision by qualified teachers from inside and outside the country, with the participation of more than 50 children in various editions.</p>',
            'description_ar' => '<p>بالتنسيق مع مشروع المخطوطات المتوسطية " مانوماد Manumed "بفرنسا، نظمت الجمعية المسابقة الوالئية في الخط العربي، وتم تنظيم ورشات في الخط العربي بتأطير أساتذة وإطارات ذو كفاءة من داخل و خارج الوطن، بمشاركة أزيد من 50 طفل في مختلف الطبعات.</p>',
            'location' => 'Ghardaia',
            'event_date' => Carbon::parse('2022-11-20'),
            'status' => 'completed',
            'participants_count' => 50
        ]);

        // Palm Leaf Craft Training
        $activity2 = Activity::create([
            'type_id' => 4,
            'title_en' => 'Traditional Crafts Training in Palm Leaf Weaving',
            'title_ar' => 'تنظيم دورات تكوينية في الصناعة التقليدية بواسطة سعف النخيل',
            'description_en' => '<p>Special training for women supervised by the craftsman Tabr al-Darwish from the sisterly Republic of Tunisia, specialized in traditional crafts using palm leaves, with the participation of more than 45 trainees.</p>',
            'description_ar' => '<p>تربص خاص للنساء من تأطير الأستاذة الحرفية: تبر الدرويش من جمهورية تونس الشقيقة مختصة في مجال الحرف التقليدية بواسطة سعف النخيل، بمشاركة أزيد من 45 متربصة.</p>',
            'location' => 'Ghardaia',
            'event_date' => Carbon::parse('2021-09-10'),
            'status' => 'completed',
            'participants_count' => 45
        ]);

        // Beekeeping Training
        $activity3 = Activity::create([
            'type_id' => 4,
            'title_en' => 'Beekeeping Training Courses',
            'title_ar' => 'تنظيم دورات تكوينية في تربية النحل',
            'description_en' => '<p>In coordination with the Agricultural Cooperative for Beekeeping in Boumerdes, the association organized 3 training courses in beekeeping. 224 trainees participated and obtained accredited certificates. This is especially important as the Algerian Sahara has job opportunities and natural resources for such wealth-producing projects.</p>',
            'description_ar' => '<p>بالتنسيق مع التعاونية الفلاحية لتربية النحل لبومرداس، نظمت الجمعية 5 دورات تكوينية في تربية النحل وقد شارك فيها 224 متربص تحصلوا على شهادة معتمدة، لاسيما وأن الصحراء الجزائرية تتوفر على مناصب الشغل وكذا المؤهلات الطبيعية لمثل هذه المشاريع المنتجة للثروة.</p>',
            'location' => 'Multiple locations',
            'event_date' => Carbon::parse('2020-07-15'),
            'status' => 'completed',
            'participants_count' => 224
        ]);

        // Alternative Medicine Training
        $activity4 = Activity::create([
            'type_id' => 4,
            'title_en' => 'Alternative Medicine Training Courses',
            'title_ar' => 'دورات التكوينية في الطب البديل',
            'description_en' => '<p>As part of promoting public health for the family and society, special training courses were organized for women supervised by Professor Fatima Zahra Janhi (certified in alternative medicine treatments and complementary medicine and marketing of medicinal herbs). The program included the following courses: treatment with medicinal herbs, treatment with massage, treatment with cupping. With the participation of more than 120 trainees.</p>',
            'description_ar' => '<p>في إطار ترقية الصحة العامة للأسرة و المجتمع نظمت دورات تكوينية خاصة للنساء من تأطير الأستاذة فاطمة الزهراء جنحي ( معتمدة في العلاجات بالطب البديل و الطب التكميلي وتسويق النباتات العشبية الطبية ) وشمل البرنامج الدورات التالية : العلاج بالأعشاب الطبية، العلاج بالتدليك، العلاج بالحجامة. بمشاركة أزيد من 120 متربصة.</p>',
            'location' => 'Ghardaia',
            'event_date' => Carbon::parse('2021-05-20'),
            'status' => 'completed',
            'participants_count' => 120
        ]);

        // Aquaculture Training
        $activity5 = Activity::create([
            'type_id' => 4,
            'title_en' => 'Aquaculture Training with Integrated Farming',
            'title_ar' => 'تنظيم دورات تكوينية في تربية المائيات المدمجة مع الفلاحة',
            'description_en' => '<p>TAGEMI organized training courses on: Productive Investment in Aquaculture and Continental Fishing, under the supervision of the Technological Institute of Maritime Fishing and Aquaculture in El Kala and in coordination with the Inter-Wilaya Chamber for Maritime Fishing and Aquaculture in Ouargla. More than 520 trainees from various wilayas of the country and from outside the country benefited from accredited certificates in several editions.</p>',
            'description_ar' => '<p>نظمت جمعية تجمي دورات تكوينية حول : الإستثمار المنتج في تربية المائيات و الصيد القاري، تحت إشراف المعهد التكنولوجي للصيد البحري و تربية المائيات بالقل و بالتنسيق مع الغرفة المشتركة ما بين الوليات للصيد البحري و تربية المائيات بورقلة، استفاد من التكوين أزيد من 520 متربص في عدة طبعات من مختلف واليات الوطن و من خارج الوطن من شهادات معتمدة.</p>',
            'location' => 'Multiple locations',
            'event_date' => Carbon::parse('2018-03-10'),
            'status' => 'completed',
            'participants_count' => 520,
            'featured' => true
        ]);
    }

    private function seedSocialActivities()
    {
        // Solidarity Caravan "Ahbab Allah" - Rggane
        $activity = Activity::create([
            'type_id' => 5,
            'title_en' => 'Solidarity Caravan "Ahbab Allah" - Rggane',
            'title_ar' => 'تنظيم قافلة تضامنية تحت شعار " أحباب الله " - رقان',
            'description_en' => '<p>Organization of the solidarity caravan "Ahbab Allah", under the slogan "Exchange gifts, you will love each other" in the Rggane circle. The caravan benefited more than 150 families from social categories. The assistance includes: more than 150 food baskets, 10 medical water beds for patients and the elderly, 10 wheelchairs for people with special needs, diapers for adults, household appliances including: 20 refrigerators, 12 washing machines, 12 stoves, 30 fans, 150 school bags with all supplies, children\'s clothing and diapers.</p>',
            'description_ar' => '<p>تنظيم القافلة التضامنية " أحباب الله" ، تحت شعار " تهادو تحابو" بدائرة رقان استفادت من القافلة أزيد من: 150 عائلة من الفئات الإجتماعية حيث تضم المساعدات التالية: أزيد من 150 سلة غذائية، 10 أسرة طبية مائية للمرضى و العجزة، 10 كراسي متحركة لذوي الإحتياجات الخاصة، حفاظات للكبار، أجهزة كهرومنزلية منها : 20 ثلاجة ، 12 آلات الغسيل، 12 مطابخ، 30 مروحة هوائية، 150 محفظة مدرسية بكل لوازمها، ألبسة أطفال و حفاظات للصغار.</p>',
            'location' => 'Rggane, Adrar',
            'event_date' => Carbon::parse('2022-02-14'),
            'status' => 'completed',
            'beneficiaries_count' => 150,
            'featured' => true
        ]);

        // Solidarity Caravan - El Meniaa and Hassi El Gara
        $activity2 = Activity::create([
            'type_id' => 5,
            'title_en' => 'Solidarity Caravan "Ahbab Allah" - El Meniaa and Hassi El Gara',
            'title_ar' => 'تنظيم القافلة التضامنية "أحباب الله" بالولاية المنتدبة المنيعة وحاسي القارة',
            'description_en' => '<p>Organization of the solidarity caravan "Ahbab Allah" in the delegated Wilaya of El Meniaa and Hassi El Gara with distribution of: 227 food baskets to a total of 227 families, 150 blankets, 285 shoes, 100 school bags with supplies, 300 school smocks for children.</p>',
            'description_ar' => '<p>تنظيم القافلة التضامنية "أحباب الله" بالولاية المنتدبة المنيعة وحاسي القارة بتوزيع: 227 سلة غذائية على مجموع 227 أسرة، 150 غطاء، 285 حذاء، 100 محفظة مدرسية بلوازمها، 300 مأزر مدرسي للأطفال.</p>',
            'location' => 'El Meniaa, Ghardaia',
            'event_date' => Carbon::parse('2023-01-20'),
            'status' => 'completed',
            'beneficiaries_count' => 227
        ]);

        // School Bag Project "Iqra"
        $activity3 = Activity::create([
            'type_id' => 5,
            'title_en' => 'School Bag Project "Iqra" - Ain Guezzam and Tin Zaouatine',
            'title_ar' => 'تنظيم القافلة التضامنية أحباب الله مشروع المحفظة المدرسية تحت شعار " إقرأ"',
            'description_en' => '<p>More than 500 school bags with school supplies, clothes and shoes were distributed to students from vulnerable families in the Wilaya of Ain Guezzam and the district of Tin Zaouatine. This activity stems from religious and national duty towards our brothers in this dear region of the country, and TAGEMI association seeks to care for these vulnerable groups in shadow areas and remote areas, especially border ones.</p>',
            'description_ar' => '<p>تم توزيع أزيد من 500 محفظة مع أدوات مدرسية، ألبسة وأحذية لفائدة تلاميذ من الفئات الهشة لولاية عين قزام ودائرة تين زواتين، و يندرج هذا النشاط انطلاقا من الواجب الديني والوطني نحو إخواننا في هذه المنطقة العزيزة علينا من الوطن و تسعى جمعية تجمي للاهتمام بهذه الفئات الهشة في مناطق الظل والمناطق النائية وخاصة الحدودية منها.</p>',
            'location' => 'Ain Guezzam, Tin Zaouatine',
            'event_date' => Carbon::parse('2023-09-15'),
            'status' => 'completed',
            'beneficiaries_count' => 500
        ]);

        // Orphan Camps
        $activity4 = Activity::create([
            'type_id' => 5,
            'title_en' => 'Orphan Summer Camps - 5 Editions',
            'title_ar' => 'مخيمات اليتيم 5 طبعات',
            'description_en' => '<p>As part of caring for and sponsoring orphans, the association organizes a day in honor of orphans in the municipality of El-Atteuf, Wilaya of Ghardaia, with the participation of orphans from various municipalities of the Wilaya. The event program includes various areas: cultural (competitions, Arabic calligraphy, free platform, theater), recreational (chess and games), health (conducting medical examinations for participating orphans followed by care for health cases requiring surgical intervention). A total of 5 orphan camps were organized in different wilayas (Bejaia, Tizi Ouzou) with a total of 351 orphans and children.</p>',
            'description_ar' => '<p>في إطار رعاية وكفالة اليتيم تنظم الجمعية تظاهرة يوم على شرف اليتيم ببلدية العطف ولاية غرداية بمشاركة أيتام من مختلف بلديات الولاية، برنامج التظاهرة يشمل مختلف المجالات: الثقافية، الترفيهية، الصحية. تم تنظيم 5 مخيمات لليتيم في ولايات مختلفة بمجموع 351 يتيم وطفل.</p>',
            'location' => 'Multiple locations - Ghardaia, Bejaia, Tizi Ouzou',
            'event_date' => Carbon::parse('2015-07-20'),
            'status' => 'completed',
            'participants_count' => 351,
            'featured' => true
        ]);

        // Support for Homeless
        $activity5 = Activity::create([
            'type_id' => 5,
            'title_en' => 'Support for Homeless Persons (SDF)',
            'title_ar' => 'التكفل بالأشخاص بدون مأوى SDF',
            'description_en' => '<p>As part of humanitarian work and embodying the values of social solidarity and in coordination with the Directorate of Social Activity of the Wilaya of Ghardaia, the association provided a hot dinner meal daily during the cold days of winter. More than 1,200 meals were distributed during the years 2010 and 2011.</p>',
            'description_ar' => '<p>في إطار العمل الإنساني و تكريس قيم التضامن الإجتماعي و بالتنسيق مع مديرية النشاط الإجتماعي لولاية غرداية، قامت الجمعية بتقديم وجبة عشاء ساخنة يوميا و هذا طيلة أيام البرد في فصل الشتاء. حيث وزعت أزيد من 1200 وجبة، خلال سنتي 2010 و 2011.</p>',
            'location' => 'Ghardaia',
            'event_date' => Carbon::parse('2010-12-15'),
            'status' => 'completed',
            'beneficiaries_count' => 1200
        ]);
    }

    private function seedHealthActivities()
    {
        // Pediatric Surgery Campaign
        $activity = Activity::create([
            'type_id' => 2,
            'title_en' => 'Free Medical and Surgical Campaign - Pediatric Surgery',
            'title_ar' => 'حملة طبية وجراحية مجانية - طب وجراحة الأطفال',
            'description_en' => '<p>TAGEMI organizes free medical and surgical campaigns specialized in pediatric surgery using the latest global techniques. The campaigns are conducted with the participation of foreign and Algerian doctors of high competence, since 2009. A total of 3,382 examinations and 1,329 surgical operations were performed across 24 editions in various wilayas.</p>',
            'description_ar' => '<p>تنظم المؤسسة حملات طبية وجراحية مجانية متخصصة في جراحة الأطفال باستعمال أحدث التقنيات العالمية بمساهمة أطباء أجانب وجزائريون ذوو كفاءة عالية، منذ سنة 2009. بمجموع 3382 فحص و1329 عملية جراحية عبر 24 طبعة في مختلف الولايات.</p>',
            'location' => 'Multiple wilayas',
            'event_date' => Carbon::parse('2024-05-15'),
            'status' => 'completed',
            'participants_count' => 3382,
            'beneficiaries_count' => 1329,
            'featured' => true
        ]);

        // Ophthalmology Campaign
        $activity2 = Activity::create([
            'type_id' => 2,
            'title_en' => 'Free Medical and Surgical Campaign - Ophthalmology',
            'title_ar' => 'حملة طبية وجراحية مجانية - طب وجراحة العيون',
            'description_en' => '<p>Medical and surgical campaigns specialized in ophthalmology (cataract and strabismus surgery) using equipment funded by the Japanese Embassy in Algeria. A total of 18,046 examinations and 3,591 surgical operations were performed across 32 editions in various wilayas since 2009.</p>',
            'description_ar' => '<p>حملات طبية وجراحية متخصصة في طب وجراحة العيون (الكتاراكت والحول) باستعمال العتاد الممول من طرف سفارة اليابان بالجزائر. بمجموع 18046 فحص و3591 عملية جراحية عبر 32 طبعة في مختلف الولايات منذ سنة 2009.</p>',
            'location' => 'Multiple wilayas',
            'event_date' => Carbon::parse('2024-05-20'),
            'status' => 'completed',
            'participants_count' => 18046,
            'beneficiaries_count' => 3591,
            'featured' => true
        ]);

        // ENT Campaign
        $activity3 = Activity::create([
            'type_id' => 2,
            'title_en' => 'Free Medical and Surgical Campaign - ENT',
            'title_ar' => 'حملة طبية وجراحية مجانية - طب وجراحة الأنف الأذن والحنجرة',
            'description_en' => '<p>Medical and surgical campaigns specialized in ENT (Ear, Nose and Throat) surgery. A total of 5,859 examinations and 1,346 surgical operations were performed across 23 editions in various wilayas.</p>',
            'description_ar' => '<p>حملات طبية وجراحية متخصصة في طب وجراحة الأنف، الأذن والحنجرة. بمجموع 5859 فحص و1346 عملية جراحية عبر 23 طبعة في مختلف الولايات.</p>',
            'location' => 'Multiple wilayas',
            'event_date' => Carbon::parse('2024-05-25'),
            'status' => 'completed',
            'participants_count' => 5859,
            'beneficiaries_count' => 1346,
            'featured' => true
        ]);

        // Breast and Cervical Cancer Screening
        $activity4 = Activity::create([
            'type_id' => 2,
            'title_en' => 'Early Detection Campaign for Breast and Cervical Cancer',
            'title_ar' => 'حملة التشخيص المبكر لسرطان الثدي وعنق الرحم',
            'description_en' => '<p>Free specialized medical examination campaign within the framework of the medical campaign without borders for early detection of breast and cervical cancer organized by TAGEMI association in coordination with the Association of the Poor for the benefit of patients, women from 19 to 59 years and over. Days: October 29, 30, 31, 2019. 791 women benefited from free medical examinations in Ouled Adrar, October 2019.</p>',
            'description_ar' => '<p>فحص طبي متخصص في إطار الحملة الطبية دول الحدود للتشخيص المبكر لسرطان الثدي و عنق الرحم من تنظيم جمعية تجمي بالتنسيق مع جمعية الفقراء لفائدة مرضى السرطان ، نساء من 19 سنة فما فوق. أيام : 29، 30 ،31 أكتوبر 2019. استفادت 791 امرأة من الفحوصات الطبية المجانية في أولف أدرار، أكتوبر 2019.</p>',
            'location' => 'Ouled Adrar',
            'event_date' => Carbon::parse('2019-10-29'),
            'status' => 'completed',
            'participants_count' => 791
        ]);

        // Multiple Specialties
        $activity5 = Activity::create([
            'type_id' => 2,
            'title_en' => 'Multi-Specialty Medical Campaign',
            'title_ar' => 'حملة طبية في مختلف التخصصات',
            'description_en' => '<p>Comprehensive medical campaigns covering multiple specialties including: Orthopedics and Joints Surgery, General Surgery, Gynecology and Obstetrics, Neurology, Urology, Dermatology, Gastroenterology, Cardiology, General Medicine, and Endocrinology. A total of 6,642 examinations and 596 surgical operations were performed.</p>',
            'description_ar' => '<p>حملات طبية شاملة في مختلف التخصصات منها: طب وجراحة العظام والمفاصل، الجراحة العامة، طب وجراحة النساء والتوليد، طب وجراحة الأعصاب، طب وجراحة المسالك البولية، طب الأمراض الجلدية، طب الجهاز الهضمي، طب أمراض القلب، الطب العام، طب وأمراض الغدد. بمجموع 6642 فحص و596 عملية جراحية.</p>',
            'location' => 'Multiple wilayas',
            'event_date' => Carbon::parse('2021-02-10'),
            'status' => 'completed',
            'participants_count' => 6642,
            'beneficiaries_count' => 596
        ]);

        // COVID-19 Medical Equipment
        $activity6 = Activity::create([
            'type_id' => 2,
            'title_en' => 'Receiving Medical Equipment from Abroad - COVID-19 Pandemic',
            'title_ar' => 'استلام مستلزمات طبية من الخارج خاصة بجائحة كوفيد',
            'description_en' => '<p>As part of the crisis cell work and volunteer management to face the Corona pandemic, and in response to the humanitarian call made by TAGEMI Foundation for Human Development, for solidarity and support for the white army by providing modern medical supplies that would save the lives of patients infected with this epidemic. Medical supplies were received for the benefit of the population of Ghardaia Wilaya through Dr. Tarchine Ibrahim Hospital, for medical supplies related to the pandemic from France in coordination with the Al-Shifa Association for French-Algerian Human Health. The medical supplies include more than 400 respirators for Corona patients and other supplies related to anesthesia and resuscitation.</p>',
            'description_ar' => '<p>في إطار عمل خلية الأزمة و إدارة التطوع لمجابهة جائحة كورونا، و تلبية للنداء الإنساني الذي قدمته مؤسسة تجمي للتنمية الإنسانية، للتضامن و مساندة الجيش الأبيض بتوفير مستلزمات طبية حديثة من شأنها إنقاذ أرواح المرضى المصابين بهذا الوباء . تم استلام لفائدة ساكنة ولاية غرداية من خلال مستشفى د. ترشين ابراهيم، لمستلزمات طبية خاصة بالجائحة من فرنسا بالتنسيق مع جمعية الشفاء للصحة اإلنسانية الفرنسية الجزائرية. المستلزمات الطبية تضم أكثر من 400 قناع للتنفس خاص بمرضى كورونا و مستلزمات خاصة بالتخدير و اإلنعاش، و أخرى لمجابهة الجائحة.</p>',
            'location' => 'Ghardaia',
            'event_date' => Carbon::parse('2020-04-15'),
            'status' => 'completed',
            'featured' => false
        ]);

        // Diapers Distribution
        $activity7 = Activity::create([
            'type_id' => 2,
            'title_en' => 'Distribution of Diapers to Associations in Ghardaia Wilaya',
            'title_ar' => 'توزيع حفاظات على جمعيات ولاية غرداية',
            'description_en' => '<p>As part of social and solidarity work, distribution of more than 68,600 baby diapers to associations and organizations working in the social field at the level of Ghardaia Wilaya, which are as follows: Algerian Red Crescent - El Atteuf branch, Arazen Sa Association - Ghardaia, Charitable Association Cooperation and Development Hai Ben Semara Ghardaia, Al-Irshad and Solidarity Association Beni Isguen, Nas El Khair Association in Metlili, Yad Al-Ihsan Association, Al-Daya Bin Dahoua.</p>',
            'description_ar' => '<p>في إطار العمل الإجتماعي و التضامني توزيع أزيد من 68.600 حفاظة أطفال حفاظات أطفال على جمعيات و منظمات تعمل في المجال الإجتماعي على مستوى ولاية غرداية و هي كالآتي: الهلال الأحمر الجزائري فرع العطف، جمعية ارازن سا – غرداية، الجمعية الخيرية التعاون والتنمية حي بن سمارة غرداية، جمعية اإلرشاد و التضامن بن يزقن، جمعية ناس الخير بمتليلي، جمعية يد اإلحسان، الضاية بن ضحوة.</p>',
            'location' => 'Ghardaia',
            'event_date' => Carbon::parse('2021-03-10'),
            'status' => 'completed',
            'beneficiaries_count' => 68600
        ]);
    }

    private function seedVisitsAndAgreements()
    {
        // Visit from European Union
        $activity = Activity::create([
            'type_id' => 6,
            'title_en' => 'Visit from European Union Delegation to TAGEMI Association',
            'title_ar' => 'زيارة وفد للإتحاد الأوروبي للجمعية',
            'description_en' => '<p>As part of a special visit to the TAGEMI association in El-Atteuf, Wilaya of Ghardaia, on Monday, February 22, 2016, a delegation from the European Union was received at the association headquarters, accompanied by the Director of the Directorate of Maritime Fishing and Aquaculture of the Wilaya of Ouargla and his accompanying staff, and the Director of the Inter-Wilaya Chamber for Maritime Fishing and Aquaculture in Ouargla, within the framework of the Economic Diversification Program called "Diveco 2", according to the agreement signed between Algeria and the European Union regarding support and development of the maritime fishing and aquaculture sector.</p>',
            'description_ar' => '<p>في إطار زيارة خاصة لجمعية تجمي بالعطف ولاية غرداية، يوم الإثنين 22 فبراير 2016، تم استقبال وفد عن الإتحاد الأوروبي بمقر الجمعية، مرفوقا بالسيد مدير مديرية الصيد البحري و تربية المائيات لولاية ورقلة و الطاقم المرافق له و السيد مدير الغرفة المشتركة مابين الوليات للصيد البحري و تربية المائيات بورقلة و هذا في إطار برنامج التنويع الاقتصادي المسمى "ديفيكو 2"، وفق الإتفاقية الممضاة مابين الجزائر والإتحاد الأوروبي بشأن دعم و تطوير قطاع الصيد البحري و تربية المائيات.</p>',
            'location' => 'El-Atteuf, Ghardaia',
            'event_date' => Carbon::parse('2016-02-22'),
            'status' => 'completed'
        ]);

        // Visit from Japan Ambassador
        $activity2 = Activity::create([
            'type_id' => 6,
            'title_en' => 'Visit from the Ambassador of Japan to TAGEMI',
            'title_ar' => 'زيارة سفير اليابان بالجزائر لجمعية تجمي',
            'description_en' => '<p>Within the framework of preparing to embody the project of acquiring medical equipment funded by the Japanese government, several visits were made by delegations from the Japanese Embassy in Algeria: Work visit for two days by a delegation from the Japanese Embassy in Algeria: October 2014. Visit by the Ambassador of Japan to Algeria Mr. FUJIWARA Masaya on Monday, October 12, 2015.</p>',
            'description_ar' => '<p>في إطار التحضير لتجسيد مشروع اقتناء عتاد طبي بتمويل من طرف الحكومة اليابانية تم إجراء عدة زيارات من طرف وفود لسفارة اليابان في الجزائر: زيارة عمل ليومين لوفد من سفارة اليابان بالجزائر: أكتوبر 2014. زيارة سفير اليابان بالجزائر السيد: FUJIWARA Masaya يوم الإثنين 12 أكتوبر 2015.</p>',
            'location' => 'El-Atteuf, Ghardaia',
            'event_date' => Carbon::parse('2015-10-12'),
            'status' => 'completed'
        ]);

        // Agreement with Japan
        $activity3 = Activity::create([
            'type_id' => 6,
            'title_en' => 'Signing Cooperation Agreement with the State of Japan',
            'title_ar' => 'توقيع اتفاقية تعاون مع دولة اليابان',
            'description_en' => '<p>The Japanese government grants TAGEMI association a financial grant to acquire specialized medical equipment in ophthalmology and eye surgery, as part of maintaining public health for society and contributing to sustainable social development and consolidating the values of solidarity and solidarity in Algerian society. TAGEMI association for the municipality of El-Atteuf, Wilaya of Ghardaia, signed a cooperation agreement on Wednesday, March 16, 2016, with His Excellency the Ambassador of Japan to Algeria at the Embassy headquarters in a ceremony attended by both the Ministry of Foreign Affairs and the Ministry of National Solidarity and Family as well as the media family. A cooperation agreement was signed consisting of a financial grant to acquire specialized medical equipment in ophthalmology and eye surgery.</p>',
            'description_ar' => '<p>الحكومة اليابانية تمنح جمعية تجمي هبة مالية لإقتناء عتاد طبي متخصص في طب و جراحة العيون، في إطار المحافظة على الصحة العامة للمجتمع و المساهمة في التنمية الإجتماعية المستدامة و ترسيخا لقيم التضامن والتكافل في المجتمع الجزائري. وقعت جمعية تجمي لبلدية العطف ولاية غرداية، إتفاقية تعاون يوم الأربعاء 16 مارس 2016 مع سعادة سفير اليابان بالجزائر بمقر السفارة في حفل حضره كلا من وزارة الشؤون الخارجية ووزارة التضامن الوطني و الأسرة و كذا الأسرة الإعلامية.</p>',
            'location' => 'Algiers - Japanese Embassy',
            'event_date' => Carbon::parse('2016-03-16'),
            'status' => 'completed',
            'featured' => true
        ]);

        // Agreement with National Association of Ophthalmologists
        $activity4 = Activity::create([
            'type_id' => 6,
            'title_en' => 'Signing Cooperation Agreement with National Association of Private Ophthalmologists',
            'title_ar' => 'توقيع اتفاقية تعاون مع الجمعية الوطنية لأطباء العيون الخواص',
            'description_en' => '<p>As part of strengthening humanitarian and solidarity work, on Saturday, January 16, 2021, at the Sheraton Hotel in Algiers capital, a cooperation agreement was concluded between TAGEMI Humanitarian Association represented by its president Mr. (Mohamed Ben Youssef), and the Association of Algerian Private Ophthalmologists represented by its president Mr. (Yacoub Said). This agreement aimed to support cooperation opportunities between the two associations and work to implement a set of free activities. This agreement will contribute, God willing, to expanding the circle of charitable and solidarity work activity across the entire national territory.</p>',
            'description_ar' => '<p>في إطار تعزيز العمل الإنساني والتضامني يوم السبت تم 16 جانفي 2021، بفندق الشيراطون بالجزائر العاصمة، إبرام اتفاقية تعاون بين جمعية تجمي الإنسانية ممثلة في رئيسها السيد (محمد بن يوسف)، و جمعية أطباء العيون الخواص الجزائريين ممثلة في رئيسها السيد (يعقوب سعيد)، وقد هدفت هذه الاتفاقية إلى دعم فرص التعاون بين الجمعيتين والعمل على تجسيد جملة من النشاطات المجانية. ستساهم هذه الاتفاقية بحول الله في توسيع دائرة نشاط العمل الخيري والتضامني عبر كامل التراب الوطني.</p>',
            'location' => 'Algiers',
            'event_date' => Carbon::parse('2021-01-16'),
            'status' => 'completed',
            'featured' => true
        ]);

        // Participation in International Peace Conference
        $activity5 = Activity::create([
            'type_id' => 6,
            'title_en' => 'Participation in International Peace Conference for Development and E-Media - Tunisia',
            'title_ar' => 'المشاركة في ملتقى السلام الدولي للتطوع واإلعلام الإلكتروني – تونس',
            'description_en' => '<p>TAGEMI Humanitarian and Cultural Association, represented by its media and communication officer, Dr. Fatima Bouhani, was honored to participate in the "International Peace Conference for Development and E-Media" in its second session in the city of Hammamet, Tunisia from: 04 to 07 August 2017. The topic of the intervention was titled: "Associative activity in Algeria and its role in spreading the spirit of cooperation and development - TAGEMI association as a model". The association representative was able to win a medal for the seriousness, novelty and importance of the topic.</p>',
            'description_ar' => '<p>تشرفت جمعية تجمي الإنسانية الثقافية ممثلة في المكلفة باإلعلام والاتصال لديها، الدكتورة: فطيمة بوهاني، بالمشاركة في " ملتقى السلام الدولي للتطوع واإلعلام الالكتروني" في دورته الثانية بمدينة الحمامات بتونس من: 04 إلى 07 أوت 2017. موضوع المداخلة بعنوان: " النشاط الجمعوي في الجزائر ودوره في بث روح التعاون والتطوع جمعية تجمي نموذجا " استطاعت ممثلة الجمعية افتكاك ميدالية من حيث جدية الموضوع وحداثته وأهميته.</p>',
            'location' => 'Hammamet, Tunisia',
            'event_date' => Carbon::parse('2017-08-04'),
            'status' => 'completed'
        ]);

        // Award from Association of Muslim Scholars
        $activity6 = Activity::create([
            'type_id' => 6,
            'title_en' => 'Honoring TAGEMI by the Association of Algerian Muslim Scholars',
            'title_ar' => 'تكريم جمعية تجمي من طرف جمعية العلماء المسلمين الجزائريين',
            'description_en' => '<p>With the grace and success from God, the Association of Algerian Muslim Scholars honors TAGEMI Humanitarian Association with the Citizenship Medal in recognition of its efforts in humanitarian and social work across the national territory.</p>',
            'description_ar' => '<p>بفضل و توفيق من الله، جمعية العلماء المسلمين الجزائريين تكرم جمعية تجمي الإنسانية بـ: وسام المواطنة نظير مجهوداتها المبذولة في العمل الإنساني والإجتماعي عبر التراب الوطني.</p>',
            'location' => 'Algeria',
            'event_date' => Carbon::parse('2022-05-20'),
            'status' => 'completed',
            'featured' => true
        ]);

        // Honoring by General Directorate of National Security
        $activity7 = Activity::create([
            'type_id' => 6,
            'title_en' => 'Honoring TAGEMI by the General Directorate of National Security',
            'title_ar' => 'تكريم جمعية تجمي من طرف المديرية العامة للأمن الوطني',
            'description_en' => '<p>By official invitation from the General Directorate of National Security, TAGEMI association participated in the activities of the Arab Day for Human Rights coinciding with March 16 of each year, where the association was honored on this occasion among the active associations nationally.</p>',
            'description_ar' => '<p>بدعوة رسمية من المديرية العامة للأمن الوطني، شاركت جمعية تجمي في فعاليات اليوم العربي لحقوق الإنسان المصادف لـ 16 مارس من كل سنة، أين تم بالمناسبة تكريم جمعية تجمي ضمن الجمعيات الفاعلة وطنيا.</p>',
            'location' => 'Algiers',
            'event_date' => Carbon::parse('2023-03-16'),
            'status' => 'completed',
            'featured' => true
        ]);

        // Agreement with Algerian Red Crescent - Biskra
        $activity8 = Activity::create([
            'type_id' => 6,
            'title_en' => 'Cooperation Agreement with Algerian Red Crescent of Biskra Wilaya',
            'title_ar' => 'اتفاقية تعاون مع الهلال الأحمر لولاية بسكرة',
            'description_en' => '<p>Signing a cooperation agreement in the humanitarian and solidarity field between TAGEMI Humanitarian Association and the Wilaya Committee of the Algerian Red Crescent for the Wilaya of Biskra on Sunday, March 19, 2023, coinciding with the anniversary of "Victory Day", at the Ahmed Reda Hohou House of Culture.</p>',
            'description_ar' => '<p>توقيع اتفاقية تعاون في المجال الإنساني و التضامني بين جمعية تجمي الإنسانية واللجنة الولائية للهلال الأحمر الجزائري لولاية بسكرة يوم الأحد 19 مارس 2023 تزامنا مع ذكرى "عيد النصر"، بدار الثقافة أحمد رضا حوحو.</p>',
            'location' => 'Biskra',
            'event_date' => Carbon::parse('2023-03-19'),
            'status' => 'completed'
        ]);

        // Visit from Japan Ambassador 2023
        $activity9 = Activity::create([
            'type_id' => 6,
            'title_en' => 'Visit from the Ambassador of Japan to TAGEMI Association - March 2023',
            'title_ar' => 'زيارة سفير دولة اليابان إلى جمعية تجمي - مارس 2023',
            'description_en' => '<p>The Ambassador of the State of Japan to Algeria in an official visit to the association, with an accompanying diplomatic delegation in the presence of guests of honor from officials and notables. This visit is part of appreciating the success of the agreement project concluded between the Embassy and the Association. His Excellency the Ambassador inspected the medical equipment and praised the association\'s keenness in caring for the safety of the equipment and the effort made in achieving the considerable number of surgical operations. His Excellency the Ambassador presented a letter of thanks and gratitude to the association in recognition of its efforts in the optimal exploitation of medical equipment.</p>',
            'description_ar' => '<p>سفير دولة اليابان بالجزائر في زيارة رسمية للجمعية، مع وفد دبلوماسي مرافق بحضور ضيوف شرف من مسؤولين و أعيان. هذه الزيارة تندرج في إطار تثمين نجاح مشروع الإتفاقية المبرمة بين السفارة والجمعية. وقد عاين سعادة السفير العتاد الطبي و ثمن حرص الجمعية في التكفل بسلامة العتاد و الجهد المبذول في تحقيق العدد المعتبر من العمليات الجراحية. أين قدم سعادة السفير رسالة شكر وعرفان للجمعية نظير جهودها في الاستغلال الأمثل للعتاد الطبي.</p>',
            'location' => 'El-Atteuf, Ghardaia',
            'event_date' => Carbon::parse('2023-03-25'),
            'status' => 'completed',
            'featured' => true
        ]);
    }
}
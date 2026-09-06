export function buildFinishing({ FormulaBlock, number, text, any, globals, parameters, global }) {
  const n = number
  parameters(1084, [['FEED_ALLOWANCE_MM', 4, 'Зазор подачи листов (мм)', 'Перенесён из параметров этапа.'], ['FILM_WASTE_PERCENT', 3, 'Технологический запас плёнки (%)', 'Перенесён из параметров этапа. Приладочная бумага печати здесь не расходуется.']])
  parameters(12761, [['CUT_WORK_REFERENCE_QTY', 20, 'Опорное количество резов', 'Существующая шкала трудоёмкости.']])
  global('packaging_allowance_mm', 'Запас размера внутри упаковки с каждой стороны (мм)', 'constant', '6')
  global('packaging_max_item_qty', 'Максимум изделий в одном коробе (шт)', 'constant', '5000')

  const laminate = new FormulaBlock('Рулонная ламинация — годные листы')
  laminate.input(...['print_sheet_width_mm', 'print_sheet_length_mm', 'print_sheet_thickness_mm', 'print_sheet_weight_g', 'postpress_sheet_qty'].map(c => globals(c, c)),
    n('sides_value', 'Сторон ламинации', 'input.values.lamination.sides'),
    n('feed_gap', 'Зазор подачи (мм)', 'stage_16436.equipment.properties.PARAMETRS.DESCRIPTION.CODE.FEED_ALLOWANCE_MM'),
    n('film_waste_pct', 'Запас плёнки (%)', 'stage_16436.equipment.properties.PARAMETRS.DESCRIPTION.CODE.FILM_WASTE_PERCENT'),
    n('machine_width', 'Предельная ширина ламинатора (мм)', 'stage_16436.equipment.properties.MAX_WIDTH.VALUE'),
    ...[['film_width','width','Ширина рулона (мм)'],['film_length','length','Длина рулона (мм)'],['film_thickness','height','Толщина слоя плёнки (мм)'],['film_weight','weight','Масса плёнки в рулоне (г)']].map(([c,k,title]) => n(c,title,`stage_16436.materialVariant.attributes.${k}`)),
    n('film_cost_rate', 'Закупка рулона плёнки (руб)', 'stage_16436.materialVariant.purchasingPrice'),
    any('film_prices','Шкала цены рулона','stage_16436.materialVariant.prices'),
    n('operation_rate','Закупка обработки листа выбранного числа сторон (руб)','stage_16436.operationVariant.purchasingPrice'),
    any('operation_prices','Шкала обработки листа выбранного числа сторон','stage_16436.operationVariant.prices'),
    text('film_name','Название плёнки','stage_16436.materialVariant.name'))
  const l = (...a) => laminate.add(...a)
  l('sides', 'toNumber(sides_value)', 'Количество сторон ламинации')
  l('orientation_a_valid', 'print_sheet_width_mm <= min(film_width, machine_width)', 'Подача стороной ширины допустима', 'bool')
  l('orientation_b_valid', 'print_sheet_length_mm <= min(film_width, machine_width)', 'Подача стороной длины допустима', 'bool')
  l('lamination_valid', '(orientation_a_valid || orientation_b_valid) && (sides == 1 || sides == 2) && film_length > 0 && film_width > 0 && print_sheet_thickness_mm > 0 && film_thickness > 0 && film_weight > 0 && postpress_sheet_qty > 0', 'Лист и плёнка совместимы', 'bool')
  l('lamination_guard', 'if(lamination_valid, 1, 1 / 0)', 'Проверка совместимости ламинации')
  l('feed_length', 'if(orientation_a_valid && (!orientation_b_valid || print_sheet_length_mm <= print_sheet_width_mm), print_sheet_length_mm, print_sheet_width_mm)', 'Минимальная длина подачи (мм)')
  l('film_used_length', '(feed_length + feed_gap) * postpress_sheet_qty * sides * (1 + film_waste_pct / 100) * lamination_guard', 'Расход плёнки с запасом (мм)')
  l('film_roll_fraction', 'film_used_length / film_length', 'Расход плёнки (рулонов)')
  l('film_cost', 'film_roll_fraction * film_cost_rate', 'Закупочная стоимость плёнки (руб)')
  l('film_base', 'film_roll_fraction * getPrice(film_roll_fraction, film_prices, true)', 'Базовая стоимость плёнки (руб)')
  l('operation_cost', 'postpress_sheet_qty * operation_rate * lamination_guard', 'Закупочная стоимость ламинации (руб)')
  l('operation_base', 'postpress_sheet_qty * getPrice(postpress_sheet_qty, operation_prices, true) * lamination_guard', 'Базовая стоимость ламинации (руб)')
  l('laminated_thickness', 'print_sheet_thickness_mm + film_thickness * sides', 'Толщина одного ламинированного листа (мм)')
  l('laminated_weight', 'print_sheet_weight_g + film_weight * print_sheet_width_mm * print_sheet_length_mm / (film_width * film_length) * sides', 'Масса одного ламинированного листа (г)')
  for (const [k,v] of Object.entries({width:'print_sheet_width_mm',length:'print_sheet_length_mm',height:'laminated_thickness',weight:'laminated_weight',materialPurchasingPrice:'film_cost',materialBasePrice:'film_base',operationPurchasingPrice:'operation_cost',operationBasePrice:'operation_base'})) laminate.output(k,v)
  for (const c of ['feed_length','film_used_length','film_roll_fraction']) laminate.extra(c,laminate.variables.find(v=>v.code===c).title)

  const cut = new FormulaBlock('Резка в готовый формат — с учётом подготовительного раскроя')
  cut.input(...['incoming_semifinished_purchasing_cost_rub','incoming_semifinished_base_cost_rub','print_sheet_width_mm','print_sheet_length_mm','print_sheet_thickness_mm','print_sheet_weight_g','finished_item_width_mm','finished_item_length_mm','finished_item_qty','print_layout_columns','print_layout_rows','postpress_sheet_qty','bleeds_usual_one_side_mm','source_material_sheet_qty','source_cut_line_qty','print_sheet_thickness_initial_mm'].map(c=>globals(c,c)),
    n('max_stack','Высота стопы выбранного резака (мм)','stage_16439.equipment.properties.PARAMETRS.DESCRIPTION.CODE.MAX_STACK_MM'),
    n('cutter_width','Ширина выбранного резака (мм)','stage_16439.equipment.properties.MAX_WIDTH.VALUE'),
    n('work_reference','Опорное количество резов','stage_16439.operationVariant.properties.PARAMETRS.DESCRIPTION.CODE.CUT_WORK_REFERENCE_QTY'),
    n('percent_min','Минимальная наценка резки (%)','stage_16439.operationVariant.properties.PARAMETRS.DESCRIPTION.CODE.percent_min'),
    n('percent_max','Максимальная наценка резки (%)','stage_16439.operationVariant.properties.PARAMETRS.DESCRIPTION.CODE.percent_max'))
  const c = (...a)=>cut.add(...a)
  c('cut_valid','print_sheet_thickness_mm > 0 && max_stack >= print_sheet_thickness_mm && max(print_sheet_width_mm, print_sheet_length_mm) <= cutter_width && print_layout_columns >= 1 && print_layout_rows >= 1 && postpress_sheet_qty > 0 && finished_item_qty > 0','Допустимость резки','bool')
  c('cut_guard','if(cut_valid, 1, 1 / 0)','Проверка резки')
  c('stack_capacity','floor(max_stack / print_sheet_thickness_mm)','Печатных листов в стопе')
  c('stack_qty','ceil(postpress_sheet_qty / max(1, stack_capacity)) * cut_guard','Количество стоп')
  c('cut_line_qty','if(bleeds_usual_one_side_mm > 0, 2 * (print_layout_columns + print_layout_rows), print_layout_columns + print_layout_rows + 2)','Линий реза на стопу')
  c('source_stack_qty','ceil(source_material_sheet_qty / max(1, floor(max_stack / print_sheet_thickness_initial_mm)))','Исходных стоп для подготовительного раскроя')
  c('source_cut_work_qty','source_stack_qty * source_cut_line_qty','Резов подготовительного раскроя')
  c('cut_work_unit_qty','cut_line_qty * stack_qty + source_cut_work_qty','Расчётных резов: подготовка и готовый формат')
  c('cut_markup_pct','min(percent_min, percent_max) + abs(percent_max - percent_min) * cut_work_unit_qty / (cut_work_unit_qty + work_reference)','Наценка резки (%)')
  c('cut_cost','incoming_semifinished_purchasing_cost_rub * cut_markup_pct / 100','Закупочная стоимость резки (руб)')
  c('cut_base','incoming_semifinished_base_cost_rub * cut_markup_pct / 100','Базовая стоимость резки (руб)')
  c('item_weight','print_sheet_weight_g * finished_item_width_mm * finished_item_length_mm / (print_sheet_width_mm * print_sheet_length_mm)','Масса одного готового изделия (г)')
  c('run_weight','item_weight * finished_item_qty','Масса готового тиража без упаковки (г)')
  c('packing_long_side','max(finished_item_width_mm, finished_item_length_mm)','Длинная сторона изделия для упаковки (мм)')
  c('packing_short_side','min(finished_item_width_mm, finished_item_length_mm)','Короткая сторона изделия для упаковки (мм)')
  for(const [k,v] of Object.entries({width:'finished_item_width_mm',length:'finished_item_length_mm',height:'print_sheet_thickness_mm',weight:'item_weight',operationPurchasingPrice:'cut_cost',operationBasePrice:'cut_base'})) cut.output(k,v)
  for(const k of ['stack_capacity','stack_qty','cut_line_qty','source_cut_work_qty','cut_work_unit_qty','cut_markup_pct','run_weight','packing_long_side','packing_short_side'])cut.extra(k,cut.variables.find(v=>v.code===k).title)

  const pack = new FormulaBlock('Упаковка — вместимость выбранного короба')
  pack.input(...['print_sheet_width_mm','print_sheet_length_mm','print_sheet_thickness_mm','print_sheet_weight_g','finished_item_qty','packaging_allowance_mm','packaging_max_item_qty'].map(c=>globals(c,c)),
    ...[['box_width','width','Ширина короба (мм)'],['box_length','length','Длина короба (мм)'],['box_height','height','Высота короба (мм)'],['box_weight','weight','Масса пустого короба (г)']].map(([c,k,title])=>n(c,title,`stage_16440.materialVariant.attributes.${k}`)),
    n('max_product_weight','Допустимая масса содержимого короба (г)','stage_16440.materialVariant.properties.PARAMETRS.DESCRIPTION.CODE.product%2Emax_weight%2Eg'),
    n('material_rate','Закупка одного короба (руб)','stage_16440.materialVariant.purchasingPrice'),any('material_prices','Цены одного короба','stage_16440.materialVariant.prices'),
    n('operation_rate','Закупка упаковки одного короба (руб)','stage_16440.operationVariant.purchasingPrice'),any('operation_prices','Цены упаковки одного короба','stage_16440.operationVariant.prices'),
    text('packaging_type','Название упаковки','stage_16440.materialVariant.name'))
  const p = (...a)=>pack.add(...a)
  p('inner_width','box_width - 2 * packaging_allowance_mm','Полезная ширина короба (мм)')
  p('inner_length','box_length - 2 * packaging_allowance_mm','Полезная длина короба (мм)')
  p('inner_height','box_height - 2 * packaging_allowance_mm','Полезная высота короба (мм)')
  p('stacks_per_box','max(floor(inner_width / print_sheet_width_mm) * floor(inner_length / print_sheet_length_mm), floor(inner_width / print_sheet_length_mm) * floor(inner_length / print_sheet_width_mm))','Стоп изделий на дне короба')
  p('capacity_by_volume','stacks_per_box * floor(inner_height / print_sheet_thickness_mm)','Вместимость по размерам (шт)')
  p('capacity_by_weight','floor(max_product_weight / print_sheet_weight_g)','Вместимость по массе содержимого (шт)')
  p('items_per_package_qty','min(capacity_by_volume, capacity_by_weight, packaging_max_item_qty)','Максимум изделий в коробе (шт)')
  p('pack_valid','inner_width > 0 && inner_length > 0 && inner_height > 0 && print_sheet_width_mm > 0 && print_sheet_length_mm > 0 && print_sheet_thickness_mm > 0 && print_sheet_weight_g > 0 && items_per_package_qty >= 1 && finished_item_qty > 0','Изделие помещается в выбранный короб','bool')
  p('package_qty','if(pack_valid, ceil(finished_item_qty / items_per_package_qty), 1 / 0)','Количество коробов')
  p('pack_material_cost','package_qty * material_rate','Закупка коробов (руб)')
  p('pack_material_base','package_qty * getPrice(package_qty, material_prices, true)','Базовая стоимость коробов (руб)')
  p('pack_operation_cost','package_qty * operation_rate','Закупочная стоимость упаковки (руб)')
  p('pack_operation_base','package_qty * getPrice(package_qty, operation_prices, true)','Базовая стоимость упаковки (руб)')
  p('total_weight','finished_item_qty * print_sheet_weight_g + package_qty * box_weight','Масса упакованного заказа (г)')
  p('aggregate_height','package_qty * box_height','Суммарная высота коробов (мм)')
  p('total_volume','box_width * box_length * aggregate_height / 1000000000','Объём упакованного заказа (м³)')
  p('max_package_weight','min(finished_item_qty, items_per_package_qty) * print_sheet_weight_g + box_weight','Максимальная масса одного короба (г)')
  for(const [k,v] of Object.entries({width:'box_width',length:'box_length',height:'aggregate_height',weight:'total_weight',materialPurchasingPrice:'pack_material_cost',materialBasePrice:'pack_material_base',operationPurchasingPrice:'pack_operation_cost',operationBasePrice:'pack_operation_base'})) pack.output(k,v)
  for(const k of ['stacks_per_box','items_per_package_qty','package_qty','total_volume','max_package_weight'])pack.extra(k,pack.variables.find(v=>v.code===k).title)
  return {laminate,cut,pack}
}

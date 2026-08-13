<?php

namespace App\Form;

use App\Entity\ProcedimentoSla;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProcedimentoSlaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('codigo', TextType::class, [
                'label' => 'Código do Procedimento',
                'attr' => ['placeholder' => 'Ex: PROC-MMG'],
            ])
            ->add('nomeProcedimento', TextType::class, [
                'label' => 'Nome do Procedimento / Exame',
                'attr' => ['placeholder' => 'Ex: Mamografia Digital'],
            ])
            ->add('limiteVerdeMinutos', IntegerType::class, [
                'label' => 'Limite Verde (Minutos)',
                'help' => 'Tempo máximo considerado normal e aceitável',
                'attr' => ['min' => 1],
            ])
            ->add('limiteAmareloMinutos', IntegerType::class, [
                'label' => 'Limite 2 - Término do Amarelo (Minutos)',
                'help' => 'Acima deste tempo o alerta passa automaticamente para Vermelho',
                'attr' => ['min' => 1],
            ])
            ->add('descricao', TextareaType::class, [
                'label' => 'Descrição / Observações',
                'required' => false,
                'attr' => ['rows' => 3, 'placeholder' => 'Ex: Exame de mamografia com tomossíntese...'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProcedimentoSla::class,
        ]);
    }
}
